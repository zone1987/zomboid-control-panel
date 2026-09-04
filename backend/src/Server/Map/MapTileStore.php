<?php

declare(strict_types=1);

namespace App\Server\Map;

/**
 * The world map tiles, as the game itself ships them.
 *
 * Project Zomboid draws its own in-game map and keeps the result in
 * media/maps/<map>/pyramid.zip: 256x256 PNG tiles named tile<col>x<row>,
 * in five zoom levels, about 50 MB in all. Level 0 is 19968x16128 --
 * exactly the world in tiles -- so one pixel is one square and one tile
 * is one cell.
 *
 * That is why this panel renders no map of its own: rendering the world
 * isometrically runs to hundreds of gigabytes, and the game has already
 * done the work.
 */
final class MapTileStore
{
    /** Tiles are square and this size at every level. */
    public const TILE_SIZE = 256;

    /** Level 0 is one pixel per world square. */
    public const WORLD_WIDTH = 19968;
    public const WORLD_HEIGHT = 16128;

    /** Deepest level in the pyramid; 0 is the most detailed. */
    public const MAX_LEVEL = 4;

    public function __construct(private readonly string $directory)
    {
    }

    public function isAvailable(): bool
    {
        return is_file($this->archivePath());
    }

    public function archivePath(): string
    {
        return $this->directory.'/pyramid.zip';
    }

    /**
     * Reads one tile straight out of the archive.
     *
     * Unpacking would cost 6582 files on disk for no gain: a zip's
     * central directory is an index, so a single tile is one seek.
     */
    public function tile(int $level, int $column, int $row): ?string
    {
        if (!$this->isValidTile($level, $column, $row) || !$this->isAvailable()) {
            return null;
        }

        $archive = new \ZipArchive();

        if ($archive->open($this->archivePath()) !== true) {
            return null;
        }

        $contents = $archive->getFromName(sprintf('%d/tile%dx%d.png', $level, $column, $row));
        $archive->close();

        return $contents === false ? null : $contents;
    }

    public function isValidTile(int $level, int $column, int $row): bool
    {
        if ($level < 0 || $level > self::MAX_LEVEL || $column < 0 || $row < 0) {
            return false;
        }

        return $column < self::columnsAt($level) && $row < self::rowsAt($level);
    }

    /** Each level halves the one below it, rounding up. */
    public static function columnsAt(int $level): int
    {
        return (int) ceil(self::WORLD_WIDTH / self::TILE_SIZE / 2 ** $level);
    }

    public static function rowsAt(int $level): int
    {
        return (int) ceil(self::WORLD_HEIGHT / self::TILE_SIZE / 2 ** $level);
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            'available' => $this->isAvailable(),
            'tileSize' => self::TILE_SIZE,
            'maxLevel' => self::MAX_LEVEL,
            'world' => ['width' => self::WORLD_WIDTH, 'height' => self::WORLD_HEIGHT],
            'levels' => array_map(
                static fn (int $level): array => [
                    'level' => $level,
                    'columns' => self::columnsAt($level),
                    'rows' => self::rowsAt($level),
                ],
                range(0, self::MAX_LEVEL),
            ),
        ];
    }
}
