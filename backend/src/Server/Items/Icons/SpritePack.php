<?php

declare(strict_types=1);

namespace App\Server\Items\Icons;

/**
 * Reads a Project Zomboid texture pack.
 *
 * A pack is a run of pages; each page lists its sprites and then embeds
 * a whole PNG holding them side by side. Two variants exist, and the
 * format is undocumented by the developers, so the three things that
 * bite are called out where they happen.
 *
 * Everything is little-endian.
 */
final readonly class SpritePack
{
    private const PZPK_MAGIC = 'PZPK';
    private const PAGE_SEPARATOR = 0xDEADBEEF;

    /** A pack larger than this is not one of ours. */
    private const MAX_PAGES = 512;

    /** @param list<SpritePage> $pages */
    public function __construct(public array $pages)
    {
    }

    /** @throws MalformedPack */
    public static function parse(string $bytes): self
    {
        $cursor = 0;
        $length = \strlen($bytes);

        if ($length < 8) {
            throw new MalformedPack('The file is too short to be a texture pack.');
        }

        if (substr($bytes, 0, 4) === self::PZPK_MAGIC) {
            // PZPK carries no page count: pages run to the end of the
            // buffer, and there is no separator to look for either.
            $cursor = 4;
            self::readUint32($bytes, $cursor);
            self::readUint32($bytes, $cursor);
            $expected = null;
        } else {
            $expected = self::readUint32($bytes, $cursor);

            if ($expected < 1 || $expected > self::MAX_PAGES) {
                throw new MalformedPack(sprintf('Page count %d is not plausible.', $expected));
            }
        }

        $pages = [];

        while ($cursor < $length && \count($pages) < self::MAX_PAGES) {
            if ($expected !== null && \count($pages) >= $expected) {
                break;
            }

            $page = self::readPage($bytes, $cursor);

            if ($page === null) {
                break;
            }

            $pages[] = $page;

            // Legacy packs separate pages with 0xDEADBEEF. Skipping it is
            // what separates a reader that finds 4,400 sprites from one
            // that finds sixty and looks like it worked.
            if ($cursor + 4 <= $length) {
                $peek = $cursor;

                if (self::readUint32($bytes, $peek) === self::PAGE_SEPARATOR) {
                    $cursor = $peek;
                }
            }

            if ($length - $cursor < 8) {
                break;
            }
        }

        if ($pages === []) {
            throw new MalformedPack('No page could be read.');
        }

        return new self($pages);
    }

    private static function readPage(string $bytes, int &$cursor): ?SpritePage
    {
        $length = \strlen($bytes);

        try {
            $name = self::readString($bytes, $cursor);
            $spriteCount = self::readUint32($bytes, $cursor);

            // The reserved word after the sprite count. Miss these four
            // bytes and every name length after it is garbage.
            self::readUint32($bytes, $cursor);
        } catch (MalformedPack) {
            return null;
        }

        if ($spriteCount > 100000) {
            return null;
        }

        $sprites = [];

        for ($i = 0; $i < $spriteCount; ++$i) {
            try {
                $spriteName = self::readString($bytes, $cursor);
                $x = self::readInt32($bytes, $cursor);
                $y = self::readInt32($bytes, $cursor);
                $w = self::readInt32($bytes, $cursor);
                $h = self::readInt32($bytes, $cursor);

                // offX, offY, origW, origH describe trimmed padding and
                // are not needed to crop.
                $cursor += 16;
            } catch (MalformedPack) {
                return null;
            }

            if ($cursor > $length) {
                return null;
            }

            $sprites[] = new Sprite($spriteName, $x, $y, $w, $h);
        }

        $start = strpos($bytes, "\x89PNG", $cursor);

        if ($start === false) {
            return null;
        }

        $end = strpos($bytes, 'IEND', $start);

        if ($end === false) {
            return null;
        }

        // IEND plus its four-byte CRC.
        $end += 8;
        $cursor = $end;

        return new SpritePage($name, $sprites, substr($bytes, $start, $end - $start));
    }

    private static function readString(string $bytes, int &$cursor): string
    {
        $length = self::readUint32($bytes, $cursor);

        if ($length > 4096 || $cursor + $length > \strlen($bytes)) {
            throw new MalformedPack('A name length is not plausible.');
        }

        $value = substr($bytes, $cursor, $length);
        $cursor += $length;

        return $value;
    }

    private static function readUint32(string $bytes, int &$cursor): int
    {
        if ($cursor + 4 > \strlen($bytes)) {
            throw new MalformedPack('The file ended mid-field.');
        }

        $value = unpack('V', substr($bytes, $cursor, 4));
        $cursor += 4;

        return $value[1];
    }

    private static function readInt32(string $bytes, int &$cursor): int
    {
        if ($cursor + 4 > \strlen($bytes)) {
            throw new MalformedPack('The file ended mid-field.');
        }

        $value = unpack('l', substr($bytes, $cursor, 4));
        $cursor += 4;

        return $value[1];
    }
}
