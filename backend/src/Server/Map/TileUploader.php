<?php

declare(strict_types=1);

namespace App\Server\Map;

use App\Storage\ObjectStorageInterface;
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

    public function __construct(
        private ObjectStorageInterface $storage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param callable(string, int, int, int): void|null $onProgress key, sent, failed, bytes
     *
     * @return array{sent: int, skipped: int, failed: int, bytes: int, keys: list<string>}
     */
    public function upload(string $directory, string $prefix, ?callable $onProgress = null): array
    {
        $filesystem = $this->storage->create();
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
                if ($this->isUnchanged($filesystem, $key, $file)) {
                    ++$skipped;

                    continue;
                }

                $written = $this->attempt(static function () use ($filesystem, $key, $file): bool {
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

                if ($written) {
                    ++$sent;
                    $bytes += $file->getSize();
                } else {
                    ++$failed;
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
            if ($onProgress !== null) {
                $onProgress($key, $sent, $failed, $bytes);
            }
        }

        return [
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
            'bytes' => $bytes,
            'keys' => $keys,
        ];
    }

    /**
     * Whether the store already holds exactly this file.
     *
     * Size first because it is free -- the listing carries it -- and a
     * changed tile is nearly always a different length. The checksum
     * settles the rest.
     */
    private function isUnchanged(FilesystemOperator $filesystem, string $key, \SplFileInfo $file): bool
    {
        $stored = null;

        $this->attempt(static function () use ($filesystem, $key, &$stored): bool {
            $stored = $filesystem->fileExists($key) ? $filesystem->fileSize($key) : null;

            return true;
        });

        if ($stored !== $file->getSize()) {
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

        foreach ($filesystem->listContents($prefix, true) as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $path = $item->path();

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
     * @return list<string> the keys that are missing or the wrong size
     */
    public function verify(string $directory, string $prefix): array
    {
        $filesystem = $this->storage->create();
        $wrong = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $key = $prefix.'/'.ltrim(str_replace($directory, '', $file->getPathname()), '/');
            $expected = $file->getSize();

            $sized = null;

            try {
                $this->attempt(static function () use ($filesystem, $key, &$sized): bool {
                    $sized = $filesystem->fileSize($key);

                    return true;
                });
            } catch (\Throwable) {
                $sized = null;
            }

            if ($sized !== $expected) {
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
