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

    public function appendAny(string $name, int $offset, string $bytes): int
    {
        $partial = $this->temporaryDirectory.'/'.$name.'.part';

        if ($offset === 0) {
            @unlink($partial);

            if (!self::looksLikeAPack($bytes)) {
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
        $partial = $this->temporaryDirectory.'/'.$name.'.part';
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
