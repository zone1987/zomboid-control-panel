<?php

declare(strict_types=1);

namespace App\Server\Items\Icons;

final readonly class ChunkedUpload
{
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private string $temporaryDirectory,
    ) {
    }

    public static function safeName(string $name): ?string
    {
        $name = basename(trim($name));

        if ($name === '' || mb_strlen($name) > 120 || !str_ends_with(mb_strtolower($name), '.pack')) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._-]+$/', $name) === 1 ? $name : null;
    }

    public function appendAny(string $name, int $offset, string $bytes, bool $mustLookLikeAPack = true): int
    {
        $partial = $this->partialFor($name);

        if ($offset === 0) {
            @unlink($partial);

            if ($mustLookLikeAPack && !self::looksLikeAPack($bytes)) {
                throw new UploadRefused('icons.notATexturePack');
            }
        }

        $written = is_file($partial) ? (int) filesize($partial) : 0;

        if ($offset !== $written) {
            throw new UploadRefused('icons.chunkOutOfOrder');
        }

        @mkdir(\dirname($partial), 0o775, true);

        if (file_put_contents($partial, $bytes, \FILE_APPEND) === false) {
            throw new UploadRefused('icons.writeFailed');
        }

        return $written + \strlen($bytes);
    }

    public function takeAny(string $name, int $expectedBytes): string
    {
        $partial = $this->partialFor($name);
        $actual = is_file($partial) ? (int) filesize($partial) : 0;

        if ($actual !== $expectedBytes) {
            @unlink($partial);

            throw new UploadRefused('icons.sizeMismatch');
        }

        $contents = @file_get_contents($partial);
        @unlink($partial);

        if ($contents === false) {
            throw new UploadRefused('icons.unreadable');
        }

        return $contents;
    }

    /** A name is already validated by the caller; basename is the belt. */
    private function partialFor(string $name): string
    {
        return $this->temporaryDirectory.'/'.basename($name).'.part';
    }

    /** Any name a model or a pack may carry, without leaving the directory. */
    public static function safeFileName(string $name, string ...$extensions): ?string
    {
        $name = basename(trim($name));

        if ($name === '' || mb_strlen($name) > 150) {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
            return null;
        }

        if ($extensions === []) {
            return $name;
        }

        $suffix = mb_strtolower(pathinfo($name, \PATHINFO_EXTENSION));

        return \in_array($suffix, $extensions, true) ? $name : null;
    }

    public static function looksLikeAPack(string $opening): bool
    {
        if (\strlen($opening) < 8) {
            return false;
        }

        if (str_starts_with($opening, 'PZPK')) {
            return true;
        }

        // Version 0: a page count, then the length of the first page's
        // name. Both are small positive numbers in any real pack.
        $pages = unpack('V', substr($opening, 0, 4))[1] ?? 0;
        $nameLength = unpack('V', substr($opening, 4, 4))[1] ?? 0;

        return $pages >= 1 && $pages <= 4096
            && $nameLength >= 1 && $nameLength <= 255;
    }

}
