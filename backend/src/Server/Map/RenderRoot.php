<?php

declare(strict_types=1);

namespace App\Server\Map;

use App\Server\Map\Textures\TexturePackStore;

/**
 * The directory pzmap2dzi is told to treat as a game installation.
 *
 * It expects media/maps and media/texturepacks under one root. Neither
 * exists here as a copy: the packs were uploaded once and the cells
 * arrive from the game server, so this stitches the two together with
 * symlinks rather than duplicating 414 MB and every cell fetched.
 */
final readonly class RenderRoot
{
    public function __construct(
        private string $directory,
        private CellFetcher $cells,
        private TexturePackStore $textures,
    ) {
    }

    public function path(): string
    {
        return $this->directory;
    }

    /**
     * Puts the layout in place, or repairs it.
     *
     * Cheap enough to call before every render: it only creates what
     * is not already there.
     */
    public function ensure(): bool
    {
        $maps = $this->directory.'/media/maps/'.basename(CellFetcher::MAP_DIRECTORY);

        @mkdir(\dirname($maps), 0o775, true);
        @mkdir($this->directory.'/media', 0o775, true);

        return $this->link($this->cells->directory(), $maps)
            && $this->link($this->textures->directory(), $this->directory.'/media/texturepacks');
    }

    /**
     * A symlink, replacing one that points somewhere stale.
     *
     * The project directory changes between the container and a
     * developer's machine, and a link left over from the other one
     * fails in a way that reads as a missing texture.
     */
    private function link(string $target, string $link): bool
    {
        if (is_link($link)) {
            if (readlink($link) === $target) {
                return true;
            }

            @unlink($link);
        }

        return @symlink($target, $link);
    }
}
