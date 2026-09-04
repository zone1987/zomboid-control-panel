<?php

declare(strict_types=1);

namespace App\Server\Storage;

use App\Entity\FtpConfig;

interface FileBrowserInterface
{
    /**
     * @return array{path: string, entries: list<array{name: string, path: string, type: string, size: int|null, lastModified: int|null}>}
     *
     * @throws StorageException
     */
    public function listDirectory(FtpConfig $config, string $path = ''): array;

    /**
     * @return array{path: string, entryCount: int, looksLikeZomboid: bool}
     *
     * @throws StorageException
     */
    public function verify(FtpConfig $config): array;

    /** @throws StorageException */
    public function exists(FtpConfig $config, string $path): bool;

    /**
     * Reads at most $maxBytes from the end of a file, so a large log does
     * not have to travel in full.
     *
     * @throws StorageException
     */
    public function readTail(FtpConfig $config, string $path, int $maxBytes = 65536): string;

    /** @throws StorageException */
    public function upload(FtpConfig $config, string $path, string $contents): void;
}
