<?php

declare(strict_types=1);

namespace App\Server\Map\Textures;

/**
 * The game's texture packs, as the renderer needs them.
 *
 * pzmap2dzi draws every wall, shelf and machine from these, and a
 * dedicated server does not ship them -- checked against a real one:
 * media/texturepacks is absent and not one .pack is among its 85
 * entries. They only exist in a full game installation, so an operator
 * uploads them once.
 */
final readonly class TexturePackStore
{
    /**
     * What the renderer reads, from its own conf/vanilla.txt.
     *
     * Five of the twenty-seven packs a game installation holds; the
     * rest are interface and item art, which the map never draws.
     */
    public const REQUIRED = [
        'Tiles2x.pack',
        'Tiles2x.floor.pack',
        'JumboTrees2x.pack',
        'JumboTreesBigs2x.pack',
        'Overlays2x.pack',
    ];

    /** Version 1 packs open with this; version 0 packs have no marker. */
    public const MAGIC = 'PZPK';

    /** No pack the game ships is anywhere near this many pages. */
    private const MAX_PLAUSIBLE_PAGES = 4096;

    /**
     * Whether these opening bytes belong to a texture pack.
     *
     * Two formats are in circulation and the game ships both:
     * JumboTrees2x.pack is version 0, which carries no PZPK marker and
     * opens straight into a page count. Demanding the marker rejects a
     * file the renderer reads perfectly well -- which is exactly what
     * happened the first time somebody uploaded all five.
     */
    public static function looksLikeAPack(string $opening): bool
    {
        if (\strlen($opening) < 8) {
            return false;
        }

        if (str_starts_with($opening, self::MAGIC)) {
            return true;
        }

        // Version 0: a page count, then the length of the first page's
        // name. Both are small positive numbers in any real pack.
        $pages = unpack('V', substr($opening, 0, 4))[1] ?? 0;
        $nameLength = unpack('V', substr($opening, 4, 4))[1] ?? 0;

        return $pages >= 1 && $pages <= self::MAX_PLAUSIBLE_PAGES
            && $nameLength >= 1 && $nameLength <= 255;
    }

    public function __construct(private string $directory)
    {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** @return array<string, int> the packs present, and their sizes */
    public function present(): array
    {
        $found = [];

        foreach (self::REQUIRED as $name) {
            $path = $this->pathFor($name);

            if (is_file($path)) {
                $found[$name] = (int) filesize($path);
            }
        }

        return $found;
    }

    /** @return list<string> the packs still needed */
    public function missing(): array
    {
        return array_values(array_diff(self::REQUIRED, array_keys($this->present())));
    }

    public function isComplete(): bool
    {
        return $this->missing() === [];
    }

    /**
     * Where a pack belongs.
     *
     * The name is checked against the list rather than sanitised: it
     * arrives from a request, and anything not on the list has no
     * business being written at all.
     */
    public function pathFor(string $name): string
    {
        if (!\in_array($name, self::REQUIRED, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a pack the renderer reads.', $name));
        }

        return $this->directory.'/'.$name;
    }

    public function remove(string $name): void
    {
        @unlink($this->pathFor($name));
    }

    public function totalBytes(): int
    {
        return array_sum($this->present());
    }
}
