<?php

declare(strict_types=1);

namespace App\Server\Map;

/**
 * An isometric render of the world, produced by pzmap2dzi.
 *
 * The game does not ship one and nobody distributes one: an operator
 * renders it from their own installation. What lands here is that
 * render's output directory -- a .dzi per floor, its tiles beside it,
 * and the map_info.json that says how world squares map onto pixels.
 *
 * Read rather than assumed: the numbers differ with the cell range and
 * the omit_levels the operator chose, and guessing them puts every
 * marker in the wrong place.
 */
final readonly class IsometricTiles
{
    /** Written by pzmap2dzi beside the layers. */
    public const INFO_FILE = 'map_info.json';

    public function __construct(private string $directory)
    {
    }

    public function isAvailable(): bool
    {
        return is_file($this->directory.'/'.self::INFO_FILE);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * The floors this render actually covers.
     *
     * Build 42 allows -32 to 31, but a render usually carries a handful,
     * so the list comes from the files rather than from the range.
     *
     * @return list<int>
     */
    public function levels(): array
    {
        $levels = [];

        foreach (glob($this->directory.'/layer*.dzi') ?: [] as $path) {
            if (preg_match('/layer(-?\d+)\.dzi$/', basename($path), $matches) === 1) {
                $levels[] = (int) $matches[1];
            }
        }

        sort($levels);

        return $levels;
    }

    /**
     * How world squares map onto pixels of this render.
     *
     * @return array<string, mixed>|null
     */
    public function geometry(): ?array
    {
        $info = $this->info();

        if ($info === null) {
            return null;
        }

        // pzmap2dzi's skip is how many pyramid levels it left off; the
        // viewer divides by 2^skip to get back to world scale.
        $scale = 2 ** (int) ($info['skip'] ?? 0);
        $square = (int) ($info['sqr'] ?? 128);

        return [
            'originX' => (int) ($info['x0'] ?? 0),
            'originY' => (int) ($info['y0'] ?? 0),
            'squareSize' => $square,
            'scale' => $scale,
            // 1.5 squares per floor, which the renderer calls LAYER_HEIGHT.
            'floorHeight' => (int) round($square * 1.5),
            'width' => (int) ($info['w'] ?? 0),
            'height' => (int) ($info['h'] ?? 0),
            'cellSize' => (int) ($info['cell_size'] ?? 256),
        ];
    }

    /** @return array<string, mixed>|null */
    public function info(): ?array
    {
        $path = $this->directory.'/'.self::INFO_FILE;

        if (!is_file($path)) {
            return null;
        }

        try {
            $info = json_decode((string) file_get_contents($path), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($info) ? $info : null;
    }

    /**
     * Resolves a path inside the render, refusing anything that leaves it.
     *
     * The path arrives from a URL, so `..` has to fail here rather than
     * anywhere further in.
     */
    public function resolve(string $path): ?string
    {
        if (str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            return null;
        }

        $full = $this->directory.'/'.ltrim($path, '/');
        $real = realpath($full);
        $root = realpath($this->directory);

        if ($real === false || $root === false || !str_starts_with($real, $root.\DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($real) ? $real : null;
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        if (!$this->isAvailable()) {
            return ['available' => false, 'levels' => [], 'geometry' => null];
        }

        return [
            'available' => true,
            'levels' => $this->levels(),
            'geometry' => $this->geometry(),
        ];
    }
}
