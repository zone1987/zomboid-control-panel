<?php

declare(strict_types=1);

namespace App\Server\Logs;

use App\Server\Storage\StorageException;

/**
 * Reads a slice of a remote file. Flysystem cannot, which is the whole
 * reason for following a log directly.
 */
interface ChunkReader
{
    /** @throws StorageException */
    public function size(string $path): int;

    /** @throws StorageException */
    public function read(string $path, int $offset, int $length): string;

    public function close(): void;
}
