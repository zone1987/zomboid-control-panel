<?php

declare(strict_types=1);

namespace App\Server\Map;

use App\Storage\ObjectStorageInterface;
use AsyncAws\S3\Result\PutObjectOutput;
use AsyncAws\S3\S3Client;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

/**
 * Moves rendered tiles into the object store.
 *
 * A full render is around 1.5 million files and 330 GB, which belongs
 * in a bucket rather than on the panel's own disk. Tiles never change
 * once written, so anything already up there is skipped.
 */
final readonly class TileUploader
{
    /**
     * A tile is retried until it is stored, not a fixed number of
     * times: a map with holes in it is worse than a render that takes
     * longer. Measured against Hetzner, a freshly created key pair
     * refused about 4 requests in 10 for its first hour and none
     * afterwards -- exactly the shape a fixed attempt count loses to.
     *
     * The ceiling exists so a genuinely broken store -- a deleted
     * bucket, a revoked key -- ends the run rather than retrying for
     * ever. At the delays below that is a little over four minutes on
     * one tile, which no transient refusal outlasts.
     */
    private const ATTEMPTS = 12;

    /** Doubling from here, capped, so a struggling store gets room. */
    private const BACKOFF_MICROSECONDS = 100_000;

    /** Waiting longer than this helps nothing and hides a real fault. */
    private const MAX_BACKOFF_MICROSECONDS = 30_000_000;

    /** Requests started before waiting for any of them. */
    private const IN_FLIGHT = 32;

    /** What S3 accepts in one DeleteObjects call. */
    private const DELETE_BATCH = 1000;

    public function __construct(
        private ObjectStorageInterface $storage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param callable(string, int, int, int): void|null $onProgress key, sent, failed, bytes
     *
     * @return array{inStore: array<string, int>, bytesHeld: int, objectsHeld: int, sent: int, skipped: int, failed: int, bytes: int, keys: list<string>}
     */
    public function upload(string $directory, string $prefix, ?callable $onProgress = null): array
    {
        $filesystem = $this->storage->create();
        // A store without a native client -- an in-memory one in a
        // test -- writes through Flysystem one file at a time.
        $client = null;

        try {
            $client = $this->storage->client();
        } catch (\Throwable) {
            $client = null;
        }

        $bucket = (string) $this->storage->bucket();
        $inStore = $this->index($filesystem, $prefix);
        $written = [];
        $flight = [];
        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $bytes = 0;
        $keys = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $key = $prefix.'/'.ltrim(str_replace($directory, '', $file->getPathname()), '/');
            $keys[] = $key;

            try {
                // Compared by content, not by existence: a re-render of
                // a changed world produces different bytes for the same
                // position, and skipping on existence alone would
                // freeze the map at its first render for ever. S3
                // returns the body's md5 as the etag for a single-part
                // upload, which is what every tile is -- verified
                // against the store this runs on.
                if ($this->isUnchanged($filesystem, $key, $file, $inStore)) {
                    ++$skipped;

                    continue;
                }

                if ($client === null) {
                    if ($this->writeThrough($filesystem, $key, $file)) {
                        ++$sent;
                        $bytes += $file->getSize();
                        $written[$key] = $file->getSize();
                    } else {
                        ++$failed;
                    }

                    if ($onProgress !== null) {
                        $onProgress($key, $sent, $failed, $bytes);
                    }

                    continue;
                }

                $flight[] = [
                    'key' => $key,
                    'path' => $file->getPathname(),
                    'size' => $file->getSize(),
                    'result' => $client->putObject([
                        'Bucket' => $bucket,
                        'Key' => $key,
                        'Body' => file_get_contents($file->getPathname()),
                    ]),
                ];

                if (\count($flight) >= self::IN_FLIGHT) {
                    $this->settle($client, $bucket, $flight, $sent, $failed, $bytes, $written, $onProgress);
                }
            } catch (\Throwable $exception) {
                ++$failed;
                $this->logger->warning('Uploading a tile failed.', [
                    'key' => $key,
                    'error' => $exception->getMessage(),
                ]);
            }

            // Reported per tile rather than per batch: watching the
            // names go past is how somebody tells a working render
            // from a stuck one, and the cost is a small file write.
        }

        if ($client !== null) {
            $this->settle($client, $bucket, $flight, $sent, $failed, $bytes, $written, $onProgress);
        }

        $held = $inStore + $written;

        return [
            'inStore' => $held,
            // What the prefix actually occupies, which is what an
            // operator is billed for -- not what this run happened to
            // send. The listing is already in hand.
            'bytesHeld' => array_sum($held),
            'objectsHeld' => \count($held),
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
            'bytes' => $bytes,
            'keys' => $keys,
        ];
    }

    private function writeThrough(FilesystemOperator $filesystem, string $key, \SplFileInfo $file): bool
    {
        return $this->attempt(static function () use ($filesystem, $key, $file): bool {
            $handle = fopen($file->getPathname(), 'rb');

            if ($handle === false) {
                return false;
            }

            try {
                $filesystem->writeStream($key, $handle);
            } finally {
                if (\is_resource($handle)) {
                    fclose($handle);
                }
            }

            return true;
        });
    }

    /**
     * Waits for the requests in flight and records how they went.
     *
     * @param list<array{key: string, path: string, size: int, result: PutObjectOutput}> $flight
     * @param callable(string, int, int, int): void|null                                 $onProgress
     */
    private function settle(
        S3Client $client,
        string $bucket,
        array &$flight,
        int &$sent,
        int &$failed,
        int &$bytes,
        array &$written,
        ?callable $onProgress,
    ): void {
        foreach ($flight as $request) {
            $ok = false;

            try {
                $request['result']->resolve();
                $ok = true;
            } catch (\Throwable) {
                $ok = $this->retrySequentially($client, $bucket, $request['key'], $request['path']);
            }

            if ($ok) {
                ++$sent;
                $bytes += $request['size'];
                $written[$request['key']] = $request['size'];
            } else {
                ++$failed;
                $this->logger->warning('Uploading a tile failed.', ['key' => $request['key']]);
            }

            if ($onProgress !== null) {
                $onProgress($request['key'], $sent, $failed, $bytes);
            }
        }

        $flight = [];
    }

    /**
     * One tile, retried on its own after a parallel attempt failed.
     */
    private function retrySequentially(S3Client $client, string $bucket, string $key, string $path): bool
    {
        return $this->attempt(static function () use ($client, $bucket, $key, $path): bool {
            $client->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => file_get_contents($path),
            ])->resolve();

            return true;
        });
    }

    /**
     * Every object under a prefix, as key to size.
     *
     * @return array<string, int>
     */
    private function index(FilesystemOperator $filesystem, string $prefix): array
    {
        $sizes = [];

        try {
            foreach ($filesystem->listContents($prefix, true) as $item) {
                if ($item->isFile()) {
                    $sizes[$item->path()] = (int) $item->fileSize();
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Listing the store failed; falling back to per-file checks.', [
                'prefix' => $prefix,
                'error' => mb_substr($exception->getMessage(), 0, 200),
            ]);
        }

        return $sizes;
    }

    /** Whether the store already holds exactly this file. */
    private function isUnchanged(
        FilesystemOperator $filesystem,
        string $key,
        \SplFileInfo $file,
        array $inStore,
    ): bool {
        if (($inStore[$key] ?? null) !== $file->getSize()) {
            return false;
        }

        $sum = null;

        $this->attempt(static function () use ($filesystem, $key, &$sum): bool {
            $sum = $filesystem->checksum($key);

            return true;
        });

        // A store that cannot answer leaves the size match standing:
        // re-uploading a tile costs a request, and refusing to skip
        // anything would send the whole world again.
        return $sum === null || $sum === md5_file($file->getPathname());
    }

    /**
     * Removes tiles the store holds that this render did not produce.
     *
     * Tiles are named by position, so a re-render overwrites what it
     * draws again -- but where a building was demolished in-game the
     * new render draws nothing, and the old tile would be served for
     * ever. Comparing what is up there against what was written is
     * cheaper and safer than emptying the prefix first: at no point is
     * the map missing tiles it still needs.
     *
     * @param list<string> $written keys this render produced
     * @param callable(int): void|null $onProgress how many are gone
     *
     * @return array{removed: int, kept: int}
     */
    public function removeStale(string $prefix, array $written, ?callable $onProgress = null): array
    {
        $filesystem = $this->storage->create();
        $keep = array_flip($written);
        $removed = 0;
        $kept = 0;

        foreach ($filesystem->listContents($prefix, true) as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $path = $item->path();

            if (isset($keep[$path])) {
                ++$kept;

                continue;
            }

            // Descriptors and the map's own metadata are written once
            // and belong to no cell.
            if (str_ends_with($path, '.dzi') || str_ends_with($path, '.json')) {
                ++$kept;

                continue;
            }

            if ($this->attempt(static function () use ($filesystem, $path): bool {
                $filesystem->delete($path);

                return true;
            })) {
                ++$removed;

                if ($onProgress !== null && $removed % 100 === 0) {
                    $onProgress($removed);
                }
            }
        }

        return ['removed' => $removed, 'kept' => $kept];
    }

    /**
     * Removes everything under a prefix.
     *
     * A render writes tiles named by their position, so a second run
     * over a changed world overwrites most of them but leaves any that
     * no longer exist -- a building demolished in-game keeps its old
     * tiles for ever. Clearing first is the only way to be sure the
     * bucket holds this render and nothing else.
     *
     * @param callable(int): void|null $onProgress how many are gone
     */
    public function clear(string $prefix, ?callable $onProgress = null): int
    {
        $filesystem = $this->storage->create();
        $removed = 0;
        $batch = [];

        foreach ($filesystem->listContents($prefix, true) as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $batch[] = $item->path();

            if (\count($batch) >= self::DELETE_BATCH) {
                $removed += $this->deleteBatch($batch);
                $batch = [];

                if ($onProgress !== null) {
                    $onProgress($removed);
                }
            }
        }

        if ($batch !== []) {
            $removed += $this->deleteBatch($batch);

            if ($onProgress !== null) {
                $onProgress($removed);
            }
        }

        return $removed;
    }

    /**
     * Deletes up to a thousand objects in one request.
     *
     * One request per object turns clearing a finished render into
     * hours; S3 takes a thousand keys at a time.
     *
     * @param list<string> $keys
     */
    private function deleteBatch(array $keys): int
    {
        if ($keys === []) {
            return 0;
        }

        $client = $this->storage->client();
        $bucket = (string) $this->storage->bucket();
        $deleted = 0;

        $sent = $this->attempt(function () use ($client, $bucket, $keys, &$deleted): bool {
            $result = $client->deleteObjects([
                'Bucket' => $bucket,
                'Delete' => [
                    'Objects' => array_map(static fn (string $key): array => ['Key' => $key], $keys),
                    'Quiet' => true,
                ],
            ]);

            foreach ($result->getErrors() as $error) {
                $this->logger->warning('The store refused to delete an object.', [
                    'key' => $error->getKey(),
                    'error' => $error->getMessage(),
                ]);
            }

            $deleted = \count($keys) - \count(iterator_to_array($result->getErrors()));

            return true;
        });

        if (!$sent) {
            // A store without batch deletion still has to be cleared.
            return $this->deleteOneByOne($keys);
        }

        return $deleted;
    }

    /** @param list<string> $keys */
    private function deleteOneByOne(array $keys): int
    {
        $filesystem = $this->storage->create();
        $removed = 0;

        foreach ($keys as $key) {
            if ($this->attempt(static function () use ($filesystem, $key): bool {
                $filesystem->delete($key);

                return true;
            })) {
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * Confirms every local file is in the store, at the right size.
     *
     * Called before anything is deleted: a tile that only half
     * arrived is gone for good once the local copy goes, and this
     * store refuses a fraction of requests, so "the upload reported
     * success" is not the same as "the tile is there".
     *
     * @param array<string, int>|null $inStore a listing to reuse, if one is at hand
     *
     * @return list<string> the keys that are missing or the wrong size
     */
    public function verify(string $directory, string $prefix, ?array $inStore = null): array
    {
        $filesystem = $this->storage->create();
        $inStore ??= $this->index($filesystem, $prefix);
        $wrong = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $key = $prefix.'/'.ltrim(str_replace($directory, '', $file->getPathname()), '/');

            if (($inStore[$key] ?? null) !== $file->getSize()) {
                $wrong[] = $key;
            }
        }

        return $wrong;
    }

    /**
     * Runs an operation until it works or the attempts run out.
     *
     * @param callable(): bool $operation
     */
    private function attempt(callable $operation): bool
    {
        $wait = self::BACKOFF_MICROSECONDS;
        $last = null;

        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            try {
                if ($operation()) {
                    return true;
                }

                return false;
            } catch (\Throwable $exception) {
                $last = $exception;
                usleep($wait);
                $wait = min($wait * 2, self::MAX_BACKOFF_MICROSECONDS);
            }
        }

        // Twelve refusals in four minutes is not a busy store; it is a
        // wrong bucket or a revoked key, and saying which beats a
        // silent zero in the failed column.
        if ($last !== null) {
            $this->logger->error('The object store refused every attempt.', [
                'attempts' => self::ATTEMPTS,
                'error' => mb_substr($last->getMessage(), 0, 400),
            ]);
        }

        return false;
    }
}
