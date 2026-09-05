<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Map;

use App\MessageHandler\RenderWorldHandler;
use App\Server\Map\CellOccupancy;
use PHPUnit\Framework\TestCase;

/**
 * How a run walks the world.
 *
 * Two things have to hold at once: the ground floor finishes first, so
 * the map becomes usable early, and a cell is fetched once rather than
 * once per floor -- a megabyte over FTP each time.
 */
final class RenderPassOrderTest extends TestCase
{
    public function testDrawsTheGroundOfFlatCellsFirst(): void
    {
        $passes = $this->passes([
            '1,1' => [0],
            '2,2' => [0, 1, 2],
            '3,3' => [0],
        ]);

        self::assertSame(0, $passes[0][0], 'The first pass is the ground.');

        $cellsInFirst = $passes[0][1];
        self::assertContains([1, 1], $cellsInFirst);
        self::assertContains([3, 3], $cellsInFirst);
        self::assertNotContains([2, 2], $cellsInFirst, 'A multi-floor cell waits its turn.');
    }

    /** Five passes over one cell means fetching its megabyte five times. */
    public function testVisitsAMultiFloorCellOnce(): void
    {
        $passes = $this->passes(['2,2' => [0, 1, 2]]);

        $floors = array_column($passes, 0);
        self::assertSame([0, 1, 2], $floors, 'Every floor it has, in order.');

        foreach ($passes as $pass) {
            self::assertSame([[2, 2]], $pass[1]);
        }
    }

    /** The cells stay on disk until the last floor that needs them. */
    public function testKeepsTheCellsUntilItsLastFloor(): void
    {
        $passes = $this->passes(['2,2' => [0, 1, 2]]);

        self::assertFalse($passes[0][2], 'Floor 0 still needs them for floor 1.');
        self::assertFalse($passes[1][2]);
        self::assertTrue($passes[2][2], 'The last floor releases them.');
    }

    public function testReleasesTheCellsOfAFlatBatchStraightAway(): void
    {
        $passes = $this->passes(['1,1' => [0], '3,3' => [0]]);

        self::assertTrue($passes[0][2]);
    }

    /** A floor nothing stands on is never a pass at all. */
    public function testSkipsAFloorNoCellHas(): void
    {
        $passes = $this->passes(['2,2' => [0, 2]]);

        self::assertSame([0, 2], array_column($passes, 0));
    }

    /**
     * @param array<string, list<int>> $cells cell name to the floors it holds
     *
     * @return list<array{int, list<array{int, int}>, bool}>
     */
    /**
     * Louisville has towers reaching floor 29 and there is a bunker at
     * -17. A fixed range of [0, 1, -1, 2, 3] surveys them and then
     * never draws them.
     */
    public function testDrawsATowerAndABunkerBeyondTheUsualRange(): void
    {
        $passes = $this->passes(['9,9' => [0, 1, 2, 17, 29], '8,8' => [0, -17]]);

        $floors = array_unique(array_column($passes, 0));

        self::assertContains(29, $floors, 'A tower block reaching 29 has to be drawn.');
        self::assertContains(-17, $floors, 'So does a bunker at -17.');
        self::assertContains(17, $floors);
    }

    /**
     * Nearest the ground first, however far the range reaches: the
     * bunker at -17 is closer to the ground than the 29th storey.
     */
    public function testOrdersFloorsOutwardsFromTheGround(): void
    {
        $passes = $this->passes(['9,9' => [0, 29, -17, 1, -1]]);

        self::assertSame([0, 1, -1, -17, 29], array_column($passes, 0));
    }

    private function passes(array $cells): array
    {
        $occupancy = [];

        foreach ($cells as $name => $floors) {
            $detail = [];

            foreach ($floors as $floor) {
                $mask = str_repeat("\0", 128);
                $mask[0] = "\1";
                $detail[(string) $floor] = ['blocks' => 1, 'total' => 1024, 'mask' => base64_encode($mask)];
            }

            $occupancy[$name] = CellOccupancy::fromSurvey([
                'minlayer' => min($floors),
                'maxlayer' => max($floors) + 1,
                'floors' => $detail,
            ]);
        }

        $method = new \ReflectionMethod(RenderWorldHandler::class, 'passes');
        $handler = (new \ReflectionClass(RenderWorldHandler::class))->newInstanceWithoutConstructor();

        return iterator_to_array($method->invoke($handler, $occupancy), false);
    }
}
