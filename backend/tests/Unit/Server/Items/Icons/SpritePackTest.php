<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Items\Icons;

use App\Server\Items\Icons\MalformedPack;
use App\Server\Items\Icons\SpritePack;
use PHPUnit\Framework\TestCase;

/**
 * The pack format is undocumented by the developers. The layout here
 * matches the byte trace taken from UI.pack in build 42.20.2, where
 * offset 4 reads 19 and offset 39 reads "Item_HandShovel".
 */
final class SpritePackTest extends TestCase
{
    public function testReadsALegacyPack(): void
    {
        $pack = SpritePack::parse($this->legacyPack([
            ['page' => 'itemThuztorFarming1', 'sprites' => [['Item_HandShovel', 0, 0, 32, 31]]],
        ]));

        self::assertCount(1, $pack->pages);
        self::assertSame('itemThuztorFarming1', $pack->pages[0]->name);
        self::assertCount(1, $pack->pages[0]->sprites);

        $sprite = $pack->pages[0]->sprites[0];
        self::assertSame('Item_HandShovel', $sprite->name);
        self::assertSame([0, 0, 32, 31], [$sprite->x, $sprite->y, $sprite->width, $sprite->height]);
    }

    /**
     * Without skipping the 0xDEADBEEF between pages a reader gets page
     * one and stops -- about sixty sprites instead of thousands, which
     * looks like success.
     */
    public function testReadsEveryPageOfALegacyPack(): void
    {
        $pack = SpritePack::parse($this->legacyPack([
            ['page' => 'one', 'sprites' => [['Item_A', 0, 0, 8, 8]]],
            ['page' => 'two', 'sprites' => [['Item_B', 0, 0, 8, 8]]],
            ['page' => 'three', 'sprites' => [['Item_C', 0, 0, 8, 8]]],
        ]));

        self::assertCount(3, $pack->pages);
        self::assertSame(['one', 'two', 'three'], array_map(
            static fn ($page): string => $page->name,
            $pack->pages,
        ));
    }

    public function testReadsAPzpkPack(): void
    {
        $pack = SpritePack::parse($this->pzpkPack([
            ['page' => 'UI20', 'sprites' => [['Item_Axe', 4, 8, 16, 16]]],
        ]));

        self::assertCount(1, $pack->pages);
        self::assertSame('Item_Axe', $pack->pages[0]->sprites[0]->name);
        self::assertSame(4, $pack->pages[0]->sprites[0]->x);
    }

    /** PZPK stores no page count; pages run to the end of the buffer. */
    public function testReadsEveryPageOfAPzpkPack(): void
    {
        $pack = SpritePack::parse($this->pzpkPack([
            ['page' => 'UI20', 'sprites' => [['Item_A', 0, 0, 8, 8]]],
            ['page' => 'UI21', 'sprites' => [['Item_B', 0, 0, 8, 8]]],
        ]));

        self::assertCount(2, $pack->pages);
    }

    public function testKeepsThePngOfEachPage(): void
    {
        $pack = SpritePack::parse($this->legacyPack([
            ['page' => 'one', 'sprites' => [['Item_A', 0, 0, 8, 8]]],
        ]));

        $png = $pack->pages[0]->png;

        self::assertStringStartsWith("\x89PNG", $png);

        // IEND and its four-byte CRC close a PNG; cutting before them
        // would leave an image no decoder accepts.
        self::assertSame('IEND', substr($png, -8, 4));
        self::assertNotFalse(@imagecreatefromstring($png), 'the slice is a readable image');
    }

    public function testTellsItemIconsFromEverythingElse(): void
    {
        $pack = SpritePack::parse($this->legacyPack([
            [
                'page' => 'one',
                'sprites' => [['Item_Axe', 0, 0, 8, 8], ['GUI_background', 0, 0, 8, 8]],
            ],
        ]));

        $icons = array_filter(
            $pack->pages[0]->sprites,
            static fn ($sprite): bool => $sprite->isItemIcon(),
        );

        self::assertCount(1, $icons);
    }

    public function testRefusesAFileThatIsTooShort(): void
    {
        $this->expectException(MalformedPack::class);
        SpritePack::parse('abc');
    }

    /** A big-endian misread produces numbers like 1543503872. */
    public function testRefusesAPageCountThatCannotBeRight(): void
    {
        $this->expectException(MalformedPack::class);
        SpritePack::parse(pack('N', 8).str_repeat("\x00", 64));
    }

    public function testRefusesAFileWithNoReadablePage(): void
    {
        $this->expectException(MalformedPack::class);
        SpritePack::parse(pack('V', 2).str_repeat("\x00", 32));
    }

    /**
     * @param list<array{page: string, sprites: list<array{0: string, 1: int, 2: int, 3: int, 4: int}>}> $pages
     */
    private function legacyPack(array $pages): string
    {
        $bytes = pack('V', \count($pages));

        foreach ($pages as $index => $page) {
            $bytes .= $this->page($page);

            // The separator follows every page in a legacy pack.
            if ($index < \count($pages) - 1) {
                $bytes .= pack('V', 0xDEADBEEF);
            }
        }

        return $bytes;
    }

    /** @param list<array{page: string, sprites: list<array<int, mixed>>}> $pages */
    private function pzpkPack(array $pages): string
    {
        $bytes = 'PZPK'.pack('V', 1).pack('V', 15);

        foreach ($pages as $page) {
            $bytes .= $this->page($page);
        }

        return $bytes;
    }

    /** @param array{page: string, sprites: list<array<int, mixed>>} $page */
    private function page(array $page): string
    {
        $bytes = pack('V', \strlen($page['page'])).$page['page'];
        $bytes .= pack('V', \count($page['sprites']));

        // The reserved word. Miss it and every later name length is junk.
        $bytes .= pack('V', 0);

        foreach ($page['sprites'] as $sprite) {
            [$name, $x, $y, $w, $h] = $sprite;

            $bytes .= pack('V', \strlen($name)).$name;
            $bytes .= pack('llll', $x, $y, $w, $h);
            $bytes .= pack('llll', 0, 0, $w, $h);
        }

        return $bytes.$this->png();
    }

    /** A one-pixel PNG, enough to be found and sliced out. */
    private function png(): string
    {
        $image = imagecreatetruecolor(16, 16);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
