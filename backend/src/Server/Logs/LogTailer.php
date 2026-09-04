<?php

declare(strict_types=1);

namespace App\Server\Logs;

use App\Entity\FtpConfig;
use App\Server\Storage\StorageException;

/**
 * Reads the part of a log that appeared since last time.
 *
 * Flysystem can only read a file from the start, so following a growing
 * log through it means fetching the whole thing on every poll. Both
 * protocols can do better when addressed directly: SFTP takes an offset
 * on the read, and FTP has the REST command for the same purpose.
 */
final readonly class LogTailer
{
    private const TIMEOUT_SECONDS = 10;

    /** A first read starts this far back rather than at the beginning. */
    public const INITIAL_WINDOW_BYTES = 16384;

    /** No single poll returns more than this, however far behind it is. */
    public const MAX_CHUNK_BYTES = 262144;

    /**
     * @return array{lines: list<string>, offset: int, truncated: bool, rotated: bool}
     *
     * @throws StorageException
     */
    public function read(FtpConfig $config, string $path, ?int $fromOffset = null): array
    {
        $absolute = $this->absolutePath($config, $path);

        $reader = $config->getProtocol() === FtpConfig::PROTOCOL_SFTP
            ? new SftpChunkReader($config, self::TIMEOUT_SECONDS)
            : new FtpChunkReader($config, self::TIMEOUT_SECONDS);

        try {
            $size = $reader->size($absolute);

            ['start' => $start, 'truncated' => $truncated, 'rotated' => $rotated]
                = self::windowFor($size, $fromOffset);

            if ($start >= $size) {
                return ['lines' => [], 'offset' => $size, 'truncated' => false, 'rotated' => $rotated];
            }

            $chunk = $reader->read($absolute, $start, $size - $start);
        } finally {
            $reader->close();
        }

        return [
            'lines' => self::splitLines($chunk, $start > 0),
            'offset' => $size,
            'truncated' => $truncated,
            'rotated' => $rotated,
        ];
    }

    /**
     * Decides where in the file to start reading.
     *
     * @return array{start: int, truncated: bool, rotated: bool}
     */
    public static function windowFor(int $size, ?int $fromOffset): array
    {
        // A log that shrank was rotated: the remembered offset points past
        // the end of a different file, so following it would return
        // nothing for ever.
        $rotated = $fromOffset !== null && $fromOffset > $size;

        $start = match (true) {
            $fromOffset === null, $rotated => max(0, $size - self::INITIAL_WINDOW_BYTES),
            default => $fromOffset,
        };

        $truncated = $size - $start > self::MAX_CHUNK_BYTES;

        return [
            'start' => $truncated ? $size - self::MAX_CHUNK_BYTES : $start,
            'truncated' => $truncated,
            'rotated' => $rotated,
        ];
    }

    /**
     * A byte offset can land inside a character or halfway through a line.
     * Both are fixed by discarding everything before the first newline,
     * which is why a first read starts a window back rather than exactly
     * where the caller asked.
     *
     * @return list<string>
     */
    public static function splitLines(string $chunk, bool $discardFirst): array
    {
        if ($discardFirst) {
            $newline = strpos($chunk, "\n");
            $chunk = $newline === false ? '' : substr($chunk, $newline + 1);
        }

        $lines = preg_split('/\R/', $chunk) ?: [];

        // A chunk ends wherever the file happened to end, so the last line
        // may still be being written. Keeping it risks showing half a line;
        // dropping it means it arrives on the next poll instead.
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));
    }

    private function absolutePath(FtpConfig $config, string $path): string
    {
        $path = ltrim(trim($path), '/');

        if ($path === '') {
            throw new StorageException('errors.pathRequired', 'No log path was given.');
        }

        $base = rtrim($config->getBasePath(), '/');

        return $base === '' ? '/'.$path : $base.'/'.$path;
    }

}
