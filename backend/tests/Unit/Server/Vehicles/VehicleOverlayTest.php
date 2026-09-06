<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Vehicles;

use App\Entity\GameServer;
use App\Server\Vehicles\VehicleOverlay;
use App\Server\Vehicles\VehicleSourceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Neither source is enough on its own: the save database has every
 * vehicle but no paint, the bridge has paint but only for loaded
 * chunks. These check the two are joined the right way round.
 */
final class VehicleOverlayTest extends TestCase
{
    /**
     * @param list<array<string, mixed>>|null $stored
     * @param list<array<string, mixed>>|null $live
     */
    private static function overlay(?array $stored, ?array $live): VehicleOverlay
    {
        return new VehicleOverlay(self::source($stored), self::source($live));
    }

    /** @param list<array<string, mixed>>|null $rows */
    private static function source(?array $rows): VehicleSourceInterface
    {
        return new class($rows) implements VehicleSourceInterface {
            /** @param list<array<string, mixed>>|null $rows */
            public function __construct(private readonly ?array $rows)
            {
            }

            public function vehicles(GameServer $server): ?array
            {
                return $this->rows;
            }
        };
    }

    /** @return array<string, mixed> */
    private static function saved(int $id, int $x = 100, int $y = 200): array
    {
        return [
            'id' => $id,
            'script' => 'Base.CarNormal',
            'x' => $x,
            'y' => $y,
            'z' => 0,
            'heading' => 90.0,
            'skin' => 0,
            'engineRunning' => false,
            'condition' => ['damaged' => false],
        ];
    }

    public function testShowsTheWholeMapWhenNobodyIsOnline(): void
    {
        $result = self::overlay([self::saved(1), self::saved(2)], [])->of(new GameServer('Test'));

        self::assertCount(2, $result['items']);
        self::assertSame('saved', $result['source']);
        self::assertSame(0, $result['loaded']);
    }

    public function testTakesThePaintFromTheBridgeForALoadedVehicle(): void
    {
        $live = [[
            'id' => 1, 'script' => 'Base.CarNormal', 'x' => 100, 'y' => 200, 'z' => 0,
            'fuel' => 40.0, 'engineRunning' => true, 'angle' => 123.4,
            'hue' => 0.25, 'saturation' => 0.8, 'value' => 0.6, 'rust' => 0.1, 'skin' => 2,
        ]];

        $result = self::overlay([self::saved(1), self::saved(2)], $live)->of(new GameServer('Test'));

        self::assertSame('both', $result['source']);
        self::assertSame(1, $result['loaded']);

        [$first, $second] = $result['items'];

        self::assertSame(0.25, $first['hue'], 'The paint only the bridge can read is missing.');
        self::assertTrue($first['live']);
        self::assertSame(2, $first['skin'], 'The live skin must win over the stored one.');

        // The vehicle the bridge cannot see keeps what the save file said.
        self::assertArrayNotHasKey('hue', $second);
        self::assertArrayNotHasKey('live', $second);
    }

    /** A moving car is where the bridge says it is, not where it was last committed. */
    public function testPrefersTheLivePositionAndFacing(): void
    {
        $live = [[
            'id' => 1, 'script' => 'Base.CarNormal', 'x' => 555, 'y' => 666, 'z' => 1,
            'fuel' => null, 'engineRunning' => true, 'angle' => 42.0,
            'hue' => null, 'saturation' => null, 'value' => null, 'rust' => null, 'skin' => null,
        ]];

        $first = self::overlay([self::saved(1, 100, 200)], $live)->of(new GameServer('Test'))['items'][0];

        self::assertSame(555, $first['x']);
        self::assertSame(666, $first['y']);
        self::assertSame(42.0, $first['heading']);
        self::assertTrue($first['engineRunning']);
    }

    /** A null from an older bridge must not overwrite a good stored value. */
    public function testKeepsTheStoredHeadingWhenTheBridgeHasNone(): void
    {
        $live = [[
            'id' => 1, 'script' => 'Base.CarNormal', 'x' => 100, 'y' => 200, 'z' => 0,
            'fuel' => null, 'engineRunning' => false, 'angle' => null,
            'hue' => null, 'saturation' => null, 'value' => null, 'rust' => null, 'skin' => null,
        ]];

        $first = self::overlay([self::saved(1)], $live)->of(new GameServer('Test'))['items'][0];

        self::assertSame(90.0, $first['heading']);
    }

    /** Without a database the bridge is all there is, and that is worth showing. */
    public function testFallsBackToTheBridgeAloneWhenThereIsNoDatabase(): void
    {
        $live = [[
            'id' => 7, 'script' => 'Base.Van', 'x' => 10, 'y' => 20, 'z' => 0,
            'fuel' => null, 'engineRunning' => false, 'angle' => null,
            'hue' => 0.5, 'saturation' => null, 'value' => null, 'rust' => null, 'skin' => null,
        ]];

        $result = self::overlay(null, $live)->of(new GameServer('Test'));

        self::assertSame('bridge', $result['source']);
        self::assertCount(1, $result['items']);
        self::assertSame(7, $result['items'][0]['id']);
    }

    public function testReportsNoSourceWhenNeitherAnswers(): void
    {
        $result = self::overlay(null, null)->of(new GameServer('Test'));

        self::assertSame('none', $result['source']);
        self::assertSame([], $result['items']);
    }
}
