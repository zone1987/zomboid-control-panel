<?php

declare(strict_types=1);

namespace App\Tests\Integration\Server\Map;

use App\Server\Map\CellOccupancy;
use App\Server\Map\CellSurvey;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Reads two real cells off the game server.
 *
 * 4,38 has three floors with a building on the upper two; 4,39 has
 * only the ground. Between them they cover both cases the render pass
 * has to tell apart.
 */
final class CellSurveyTest extends TestCase
{
    private const CELLS = __DIR__.'/../../../Fixtures/map-cells';

    protected function setUp(): void
    {
        if (!$this->survey()->isAvailable()) {
            self::markTestSkipped('The renderer is not installed here.');
        }
    }

    public function testReadsTheFloorRangeWithoutOpeningTheLotpack(): void
    {
        $ranges = $this->survey()->floorRanges(self::CELLS);

        self::assertSame(['minlayer' => 0, 'maxlayer' => 3], $ranges['4,38']);
        self::assertSame(['minlayer' => 0, 'maxlayer' => 1], $ranges['4,39']);
    }

    public function testFindsTheGroundSolidAndTheFloorsAboveNearlyEmpty(): void
    {
        $cells = $this->survey()->occupancy(self::CELLS);

        $upstairs = $cells['4,38'];

        self::assertSame(1024, $upstairs->blocksOn(0), 'The ground is built everywhere.');
        self::assertLessThan(50, $upstairs->blocksOn(1), 'Floor 1 is a building, not a landscape.');
        self::assertLessThan(50, $upstairs->blocksOn(2));

        self::assertSame([0, 1, 2], $upstairs->floors());
    }

    /** Four passes out of five would draw nothing for a cell like this. */
    public function testReportsOnlyTheGroundForAFlatCell(): void
    {
        $cells = $this->survey()->occupancy(self::CELLS);

        self::assertSame([0], $cells['4,39']->floors());
        self::assertTrue($cells['4,39']->hasContent(0));
        self::assertFalse($cells['4,39']->hasContent(1));
        self::assertFalse($cells['4,39']->hasContent(-1));
    }

    public function testAnswersForOneNamedCell(): void
    {
        $cells = $this->survey()->occupancy(self::CELLS, [[4, 39]]);

        self::assertArrayHasKey('4,39', $cells);
        self::assertArrayNotHasKey('4,38', $cells);
    }

    /** The mask carries the block layout, not just a count. */
    public function testMarksTheBlocksThatHoldSomething(): void
    {
        $cells = $this->survey()->occupancy(self::CELLS);
        $ground = $cells['4,39'];

        $marked = 0;

        for ($x = 0; $x < 32; ++$x) {
            for ($y = 0; $y < 32; ++$y) {
                if ($ground->blockHasContent(0, $x, $y)) {
                    ++$marked;
                }
            }
        }

        self::assertSame(1024, $marked, 'Every block of a built ground floor is marked.');
        self::assertFalse($ground->blockHasContent(0, 99, 99));
    }

    public function testSaysNothingWhenTheDirectoryIsEmpty(): void
    {
        self::assertSame([], $this->survey()->occupancy(sys_get_temp_dir().'/nothing-here'));
    }

    private function survey(): CellSurvey
    {
        // .env points at the image's own copy; a checkout has it beside
        // the backend instead, and the test environment does not read
        // the .env.local that would say so.
        foreach (self::candidates() as [$renderer, $python]) {
            if (is_file($renderer.'/main.py') && is_file($python)) {
                return new CellSurvey(new NullLogger(), $renderer, $python);
            }
        }

        return new CellSurvey(new NullLogger(), null, null);
    }

    /** @return list<array{string, string}> */
    private static function candidates(): array
    {
        $paths = [];

        $configured = self::setting('PZMAP_RENDERER_PATH');

        if ($configured !== null) {
            $paths[] = [
                $configured,
                self::setting('PZMAP_PYTHON') ?? $configured.'/.venv/bin/python',
            ];
        }

        $beside = \dirname(__DIR__, 5).'/renderer';
        $paths[] = [$beside, $beside.'/.venv/bin/python'];

        return $paths;
    }

    private static function setting(string $name): ?string
    {
        $value = $_SERVER[$name] ?? getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
