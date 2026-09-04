<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Map;

use App\Server\Map\TileGeometry;
use PHPUnit\Framework\TestCase;

/**
 * Turning a requested tile back into the cells that produce it.
 *
 * The numbers come from a real render of cell 47x27 and from the tiles
 * the browser actually asked for while looking at it, so this is
 * checked against observation rather than against the formula alone.
 */
final class TileGeometryTest extends TestCase
{
    /** As pzmap2dzi wrote it for a full-resolution render. */
    private const GEOMETRY = [
        'originX' => 1036288,
        'originY' => -143392,
        'squareSize' => 128,
        'scale' => 1,
        'cellSize' => 256,
    ];

    private const TILE_SIZE = 1024;
    private const DEEPEST = 22;

    /**
     * Cell 47x27 renders into tile 1332_459 at the deepest level, and
     * that file is 184 KB in a real render -- checked both ways round
     * against the tiles on disk.
     */
    public function testFindsTheCellATileAtTheDeepestLevelComesFrom(): void
    {
        $cells = $this->geometry()->cellsUnder(22, 1332, 459);

        self::assertContains([47, 27], $cells, sprintf(
            'Expected cell 47,27 among %s.',
            json_encode($cells),
        ));
    }

    /** A level up covers the same ground with half the tile index. */
    public function testFindsTheSameCellOneLevelUp(): void
    {
        self::assertContains([47, 27], $this->geometry()->cellsUnder(21, 666, 229));
    }

    /**
     * A tile at a shallow level covers a lot of world, so it has to
     * report every cell under it rather than only its corners.
     */
    public function testAShallowTileCoversManyCells(): void
    {
        $cells = $this->geometry()->cellsUnder(16, 20, 7);

        self::assertGreaterThan(4, \count($cells));
    }

    public function testADeepTileCoversOneOrFourCells(): void
    {
        $cells = $this->geometry()->cellsUnder(22, 1332, 459);

        self::assertLessThanOrEqual(4, \count($cells));
    }

    /** Neighbouring tiles land on neighbouring cells, not at random. */
    public function testNeighbouringTilesGiveNeighbouringCells(): void
    {
        $here = $this->geometry()->cellsUnder(22, 1332, 459);
        $right = $this->geometry()->cellsUnder(22, 1333, 459);

        $distance = min(array_map(
            static fn (array $a): int => min(array_map(
                static fn (array $b): int => abs($a[0] - $b[0]) + abs($a[1] - $b[1]),
                $right,
            )),
            $here,
        ));

        self::assertLessThanOrEqual(1, $distance);
    }

    /**
     * omit_levels quarters the image and declares a scale to match, so
     * the same place carries a different tile number -- 333_114 rather
     * than 1332_459 -- and still has to resolve to the same cell.
     */
    public function testATrimmedPyramidFindsTheSameCell(): void
    {
        $trimmed = TileGeometry::fromGeometry(
            [...self::GEOMETRY, 'scale' => 4],
            self::TILE_SIZE,
            self::DEEPEST - 2,
        )->cellsUnder(20, 333, 114);

        self::assertContains([47, 27], $trimmed);
    }

    /**
     * The floor belongs in the transform, but it barely moves the
     * answer: a floor shifts the image 192 pixels and a cell is 8192
     * tall, so it takes 43 floors to cross a border -- more than build
     * 42 has. Asking for a different cell would be asking for something
     * that cannot happen; what matters is that the same tile keeps
     * resolving to the same cell whichever floor is being drawn.
     */
    public function testTheFloorDoesNotThrowTheCellOff(): void
    {
        $ground = $this->geometry()->cellsUnder(22, 1332, 459, floor: 0);

        foreach ([1, 7, 31, -1] as $floor) {
            self::assertContains(
                [47, 27],
                $this->geometry()->cellsUnder(22, 1332, 459, floor: $floor),
                sprintf('Floor %d lost the cell.', $floor),
            );
        }

        self::assertContains([47, 27], $ground);
    }

    private function geometry(): TileGeometry
    {
        return TileGeometry::fromGeometry(self::GEOMETRY, self::TILE_SIZE, self::DEEPEST);
    }
}
