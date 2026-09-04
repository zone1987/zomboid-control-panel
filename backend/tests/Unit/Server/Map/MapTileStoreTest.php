<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Map;

use App\Server\Map\MapTileStore;
use PHPUnit\Framework\TestCase;

/**
 * The numbers here come from the game's own pyramid.zip: level 0 is
 * 78x63 tiles of 256 pixels, which is 19968x16128 -- exactly the world
 * in squares. One pixel is one square, one tile is one cell.
 */
final class MapTileStoreTest extends TestCase
{
    public function testLevelZeroIsOnePixelPerWorldSquare(): void
    {
        self::assertSame(78, MapTileStore::columnsAt(0));
        self::assertSame(63, MapTileStore::rowsAt(0));
        self::assertSame(19968, 78 * MapTileStore::TILE_SIZE);
        self::assertSame(16128, 63 * MapTileStore::TILE_SIZE);
    }

    public function testEachLevelHalvesTheOneBelowRoundingUp(): void
    {
        self::assertSame([78, 39, 20, 10, 5], array_map(
            static fn (int $level): int => MapTileStore::columnsAt($level),
            range(0, 4),
        ));

        self::assertSame([63, 32, 16, 8, 4], array_map(
            static fn (int $level): int => MapTileStore::rowsAt($level),
            range(0, 4),
        ));
    }

    public function testRefusesATileOutsideTheGrid(): void
    {
        $store = new MapTileStore('/nowhere');

        self::assertTrue($store->isValidTile(0, 77, 62));
        self::assertFalse($store->isValidTile(0, 78, 0));
        self::assertFalse($store->isValidTile(0, 0, 63));
        self::assertFalse($store->isValidTile(4, 5, 0));
        self::assertTrue($store->isValidTile(4, 4, 3));
    }

    public function testRefusesALevelThePyramidDoesNotHave(): void
    {
        $store = new MapTileStore('/nowhere');

        self::assertFalse($store->isValidTile(-1, 0, 0));
        self::assertFalse($store->isValidTile(5, 0, 0));
        self::assertFalse($store->isValidTile(0, -1, 0));
    }

    public function testReportsItselfUnavailableWithoutAnArchive(): void
    {
        $store = new MapTileStore(sys_get_temp_dir().'/'.uniqid('no-map-', true));

        self::assertFalse($store->isAvailable());
        self::assertNull($store->tile(0, 0, 0));
        self::assertFalse($store->describe()['available']);
    }

    public function testDescribesEveryLevelForTheInterface(): void
    {
        $levels = (new MapTileStore('/nowhere'))->describe()['levels'];

        self::assertCount(5, $levels);
        self::assertSame(['level' => 0, 'columns' => 78, 'rows' => 63], $levels[0]);
    }

    public function testReadsATileStraightOutOfTheArchive(): void
    {
        $directory = sys_get_temp_dir().'/'.uniqid('map-', true);
        mkdir($directory);

        $archive = new \ZipArchive();
        $archive->open($directory.'/pyramid.zip', \ZipArchive::CREATE);
        $archive->addFromString('2/tile3x4.png', 'not really a png');
        $archive->close();

        $store = new MapTileStore($directory);

        self::assertTrue($store->isAvailable());
        self::assertSame('not really a png', $store->tile(2, 3, 4));
        self::assertNull($store->tile(2, 4, 4));

        unlink($directory.'/pyramid.zip');
        rmdir($directory);
    }
}
