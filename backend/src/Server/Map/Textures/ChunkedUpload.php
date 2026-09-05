<?php

declare(strict_types=1);

namespace App\Server\Map\Textures;

/**
 * Receives a large file in pieces.
 *
 * Tiles2x.pack is 306 MB, and the production image allows a 16 MB
 * request -- raising that would apply to every endpoint, not just this
 * one. Pieces also mean a connection that drops at 280 MB costs one
 * piece rather than the whole upload.
 */
final readonly class ChunkedUpload
{
    /** Comfortably inside the 16 MB the container accepts. */
    public const CHUNK_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private TexturePackStore $store,
        private string $temporaryDirectory,
    ) {
    }

    /**
     * A name that is safe to write, or null.
     *
     * Item packs cannot be checked against a list the way the five
     * renderer packs can: a mod ships its own, under whatever name its
     * author chose. So the name is constrained instead of enumerated.
     */
    public static function safeName(string $name): ?string
    {
        $name = basename(trim($name));

        if ($name === '' || mb_strlen($name) > 120 || !str_ends_with(mb_strtolower($name), '.pack')) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._-]+$/', $name) === 1 ? $name : null;
    }

    /**
     * Appends one piece, and returns how many bytes have arrived.
     *
     * Pieces must arrive in order; an offset that does not match what
     * is already there is refused rather than written to the wrong
     * place, which would corrupt the pack silently.
     *
     * @throws UploadRefused
     */
    public function append(string $name, int $offset, string $bytes): int
    {
        $partial = $this->partialPathFor($name);

        if ($offset === 0) {
            @unlink($partial);

            if (!TexturePackStore::looksLikeAPack($bytes)) {
                throw new UploadRefused('map.notATexturePack');
            }
        }

        $written = is_file($partial) ? (int) filesize($partial) : 0;

        if ($offset !== $written) {
            throw new UploadRefused('map.chunkOutOfOrder');
        }

        @mkdir(\dirname($partial), 0o775, true);

        if (file_put_contents($partial, $bytes, \FILE_APPEND) === false) {
            throw new UploadRefused('map.writeFailed');
        }

        return $written + \strlen($bytes);
    }

    /**
     * Puts a finished upload in place.
     *
     * The size is checked first: a piece lost in transit leaves a pack
     * that parses far enough to look valid and then renders holes.
     *
     * @throws UploadRefused
     */
    public function finish(string $name, int $expectedBytes): int
    {
        $partial = $this->partialPathFor($name);
        $actual = is_file($partial) ? (int) filesize($partial) : 0;

        if ($actual !== $expectedBytes) {
            @unlink($partial);

            throw new UploadRefused('map.sizeMismatch');
        }

        if (!rename($partial, $this->store->pathFor($name))) {
            throw new UploadRefused('map.writeFailed');
        }

        return $actual;
    }

    public function discard(string $name): void
    {
        @unlink($this->partialPathFor($name));
    }

    /** How much of this pack has already arrived. */
    public function received(string $name): int
    {
        $partial = $this->partialPathFor($name);

        return is_file($partial) ? (int) filesize($partial) : 0;
    }

    /**
     * The same as append(), for a pack whose name is not on the list.
     *
     * @throws UploadRefused
     */
    public function appendAny(string $name, int $offset, string $bytes): int
    {
        $partial = $this->temporaryDirectory.'/'.$name.'.part';

        if ($offset === 0) {
            @unlink($partial);

            if (!TexturePackStore::looksLikeAPack($bytes)) {
                throw new UploadRefused('icons.notATexturePack');
            }
        }

        $written = is_file($partial) ? (int) filesize($partial) : 0;

        if ($offset !== $written) {
            throw new UploadRefused('map.chunkOutOfOrder');
        }

        @mkdir(\dirname($partial), 0o775, true);

        if (file_put_contents($partial, $bytes, \FILE_APPEND) === false) {
            throw new UploadRefused('map.writeFailed');
        }

        return $written + \strlen($bytes);
    }

    /**
     * Reads a finished upload back and removes it.
     *
     * Item packs are cut into icons and then have no further use, so
     * nothing keeps the 54 MB around.
     *
     * @throws UploadRefused
     */
    public function takeAny(string $name, int $expectedBytes): string
    {
        $partial = $this->temporaryDirectory.'/'.$name.'.part';
        $actual = is_file($partial) ? (int) filesize($partial) : 0;

        if ($actual !== $expectedBytes) {
            @unlink($partial);

            throw new UploadRefused('map.sizeMismatch');
        }

        $contents = @file_get_contents($partial);
        @unlink($partial);

        if ($contents === false) {
            throw new UploadRefused('map.unreadable');
        }

        return $contents;
    }

    private function partialPathFor(string $name): string
    {
        // pathFor refuses a name that is not a pack, which is the guard
        // against a traversal reaching this far.
        $this->store->pathFor($name);

        return $this->temporaryDirectory.'/'.$name.'.part';
    }
}
