<?php

declare(strict_types=1);

namespace App\Server\Map;

use App\Storage\ObjectStorageInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads one file of a render, wherever it happens to be.
 *
 * A finished render lives in the object store: 330 GB never fits on a
 * panel's own disk, and every tile is removed locally once the store
 * confirms it. During a run the newest tiles are still on disk, so
 * both places are asked -- local first, because it is free.
 */
final readonly class TileReader
{
    /**
     * Where build 42's render lives in the store.
     *
     * Named for the build rather than "map" so a later one, or a mod
     * map, sits beside it instead of overwriting it tile by tile.
     */
    public const PREFIX = 'B42/base';

    public function __construct(
        private IsometricTiles $tiles,
        private ObjectStorageInterface $storage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The layer descriptors a render of this shape would have written.
     *
     * @return list<string>
     */
    private function descriptorNames(string $info): array
    {
        try {
            $decoded = json_decode($info, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $min = (int) ($decoded['minlayer'] ?? -1);
        $max = (int) ($decoded['maxlayer'] ?? 3);
        $names = [];

        for ($layer = $min; $layer < $max; ++$layer) {
            $names[] = sprintf('layer%d.dzi', $layer);
        }

        return $names;
    }

    /** @return resource|null */
    public function stream(string $path)
    {
        if (!$this->storage->isConfigured()) {
            return null;
        }

        // The path comes from a URL; anything leaving the prefix must
        // fail here rather than further in.
        if (str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            return null;
        }

        $key = self::PREFIX.'/'.ltrim($path, '/');

        try {
            $filesystem = $this->storage->create();

            if (!$filesystem->fileExists($key)) {
                return null;
            }

            return $filesystem->readStream($key);
        } catch (\Throwable $exception) {
            $this->logger->warning('Reading a tile from the object store failed.', [
                'path' => $path,
                'error' => mb_substr($exception->getMessage(), 0, 200),
            ]);

            return null;
        }
    }

    /**
     * Whether a render exists at all, locally or in the store.
     *
     * A store-only render has its map_info.json copied down: the
     * geometry is read on every request, and fetching it over the
     * network each time would be absurd for a few hundred bytes.
     */
    public function hasRender(): bool
    {
        $stream = $this->stream(IsometricTiles::INFO_FILE);

        if ($stream === null) {
            return false;
        }

        $info = stream_get_contents($stream);
        fclose($stream);

        if (!\is_string($info) || $info === '') {
            return false;
        }

        $this->tiles->remember($info);

        // The floor list and the tile size come from the descriptors,
        // which are read on every status request; four small files are
        // worth keeping rather than fetching again and again.
        foreach ($this->descriptorNames($info) as $name) {
            $descriptor = $this->stream($name);

            if ($descriptor === null) {
                continue;
            }

            $contents = stream_get_contents($descriptor);
            fclose($descriptor);

            if (\is_string($contents) && $contents !== '') {
                $this->tiles->rememberDescriptor($name, $contents);
            }
        }

        return true;
    }
}
