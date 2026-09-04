<?php

declare(strict_types=1);

namespace App\Server\Storage;

use App\Entity\FtpConfig;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToConnectToFtpHost;
use League\Flysystem\PhpseclibV3\UnableToConnectToSftpHost;
use League\Flysystem\PhpseclibV3\UnableToAuthenticate;
use Psr\Log\LoggerInterface;

/**
 * Read-only exploration of a game server's filesystem, so an operator can
 * find media/lua/server rather than having to know its absolute path.
 */
final readonly class ServerFileBrowser implements FileBrowserInterface
{
    private const MAX_ENTRIES = 500;

    public function __construct(
        private ServerStorageFactory $storage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{path: string, entries: list<array{name: string, path: string, type: string, size: int|null, lastModified: int|null}>}
     */
    public function listDirectory(FtpConfig $config, string $path = ''): array
    {
        $path = $this->normalise($path);

        try {
            $listing = $this->storage->create($config)
                ->listContents($path, false)
                ->sortByPath()
                ->toArray();
        } catch (\Throwable $exception) {
            throw $this->translate($exception);
        }

        $entries = [];

        foreach (\array_slice($listing, 0, self::MAX_ENTRIES) as $item) {
            \assert($item instanceof StorageAttributes);

            $entries[] = [
                'name' => basename($item->path()),
                'path' => $item->path(),
                'type' => $item->isDir() ? 'directory' : 'file',
                'size' => $item->isFile() ? $item->fileSize() : null,
                'lastModified' => $item->lastModified(),
            ];
        }

        // Directories first, then names, so the listing reads like a file manager.
        usort($entries, static fn (array $a, array $b): int
            => [$a['type'] === 'file', $a['name']] <=> [$b['type'] === 'file', $b['name']]);

        return ['path' => $path, 'entries' => $entries];
    }

    /**
     * Confirms the credentials work, and reports what the base path holds.
     *
     * @return array{path: string, entryCount: int, looksLikeZomboid: bool}
     */
    public function verify(FtpConfig $config): array
    {
        $listing = $this->listDirectory($config, '');

        $names = array_column($listing['entries'], 'name');

        return [
            'path' => $config->getBasePath(),
            'entryCount' => \count($listing['entries']),
            // A hint, not a guarantee: these are what a Zomboid server root
            // usually contains.
            'looksLikeZomboid' => (bool) array_intersect(
                array_map('strtolower', $names),
                ['media', 'server', 'logs', 'saves', 'db', 'mods'],
            ),
        ];
    }

    public function exists(FtpConfig $config, string $path): bool
    {
        try {
            return $this->storage->create($config)->directoryExists($this->normalise($path));
        } catch (\Throwable $exception) {
            throw $this->translate($exception);
        }
    }

    public function upload(FtpConfig $config, string $path, string $contents): void
    {
        try {
            $this->storage->create($config)->write($this->normalise($path), $contents);
        } catch (\Throwable $exception) {
            throw $this->translate($exception);
        }
    }

    /**
     * Rejects traversal outright: the base path is the boundary, and a
     * request that tries to leave it is a bug or an attack, never routine.
     */
    private function normalise(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '') {
            return '';
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                throw new StorageException('storage.pathTraversal', 'Path may not contain "..".');
            }
        }

        return $path;
    }

    private function translate(\Throwable $exception): \Throwable
    {
        if ($exception instanceof StorageException) {
            return $exception;
        }

        $this->logger->info('Server storage operation failed.', ['exception' => $exception]);

        $key = match (true) {
            $exception instanceof UnableToAuthenticate => 'storage.authenticationFailed',
            $exception instanceof UnableToConnectToSftpHost,
            $exception instanceof UnableToConnectToFtpHost => 'storage.unreachable',
            $exception instanceof FilesystemException => 'storage.operationFailed',
            default => 'storage.operationFailed',
        };

        // phpseclib reports a refused password as a plain runtime error,
        // so the message is the only thing left to go on.
        if ($key !== 'storage.authenticationFailed'
            && preg_match('/authenticat|password|permission denied/i', $exception->getMessage()) === 1) {
            $key = 'storage.authenticationFailed';
        }

        return new StorageException($key, $exception->getMessage(), $exception);
    }
}
