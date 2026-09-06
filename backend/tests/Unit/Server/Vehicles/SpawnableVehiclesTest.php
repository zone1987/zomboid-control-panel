<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Vehicles;

use App\Server\Vehicles\Models\VehicleCatalogue;
use App\Server\Vehicles\SpawnableVehicles;
use PHPUnit\Framework\TestCase;

/**
 * The body grouping, which decides what the vehicle page's top grid holds.
 */
final class SpawnableVehiclesTest extends TestCase
{
    /**
     * The one case the paint mask gets wrong: vehicle_pickuptruck_mask
     * paints both vehicles, so the mask alone listed Chevalier D6 liveries
     * under Dash Bulldriver.
     */
    public function testTellsTheTwoPickupsApart(): void
    {
        self::assertNotSame(
            self::bodyOf('Base.PickUpTruck'),
            self::bodyOf('Base.PickUpVan'),
        );
    }

    public function testKeepsEveryPickupTruckVariantTogether(): void
    {
        $body = self::bodyOf('Base.PickUpTruck');

        foreach (['PickUpTruckLightsFire', 'PickUpTruckSmashedFront', 'PickUpTruckMccoy'] as $variant) {
            self::assertSame($body, self::bodyOf('Base.'.$variant), $variant);
        }
    }

    public function testKeepsEveryPickupVanVariantTogether(): void
    {
        $body = self::bodyOf('Base.PickUpVan');

        foreach (['PickUpVanLightsPolice', 'PickUpVanSmashedLeft', 'PickUpVanMccoy'] as $variant) {
            self::assertSame($body, self::bodyOf('Base.'.$variant), $variant);
        }
    }

    /**
     * A damaged step van is the same step van. Grouping on the model name
     * split it into a group per wreck, because the mesh files spell damage
     * four different ways.
     */
    public function testKeepsDamagedVariantsWithTheirShell(): void
    {
        $body = self::bodyOf('Base.StepVan');

        foreach (['StepVanSmashedFront', 'StepVanSmashedLeft', 'StepVanSmashedRear'] as $variant) {
            self::assertSame($body, self::bodyOf('Base.'.$variant), $variant);
        }
    }

    public function testGroupsEveryBurntHullAsAWreck(): void
    {
        foreach (['CarNormalBurnt', 'SUVBurnt', 'VanBurnt'] as $script) {
            self::assertSame(SpawnableVehicles::WRECKS, self::bodyOf('Base.'.$script), $script);
        }
    }

    /**
     * One group per shell an operator would name, plus the wrecks. More
     * would mean a wall of one-entry tiles; fewer would mix two vehicles.
     */
    public function testGroupsTheCatalogueIntoTheExpectedBodies(): void
    {
        $bodies = [];

        foreach (VehicleCatalogue::scripts() as $script) {
            $bodies[self::bodyOf('Base.'.$script)] = true;
        }

        self::assertCount(22, $bodies);
    }

    private static function bodyOf(string $script): string
    {
        $method = new \ReflectionMethod(SpawnableVehicles::class, 'bodyOf');

        return (string) $method->invoke(null, $script, VehicleCatalogue::find($script));
    }
}
