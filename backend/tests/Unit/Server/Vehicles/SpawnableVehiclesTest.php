<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Vehicles;

use App\Server\Vehicles\SpawnableVehicles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpawnableVehiclesTest extends TestCase
{
    #[DataProvider('models')]
    public function testReducesAModelToItsShell(string $model, string $expected): void
    {
        self::assertSame($expected, SpawnableVehicles::shellOf($model));
    }

    /** @return iterable<string, array{string, string}> */
    public static function models(): iterable
    {
        yield 'a plain model keeps its name' => ['Vehicles_SUV', 'SUV'];
        yield 'the damage state is a variant' => ['Vehicles_SUVSmashedFront', 'SUV'];
        yield 'so is every other side' => ['Vehicles_SUVSmashedRear', 'SUV'];
        yield 'the no-random marker is a variant' => ['Vehicles_Van_NoRandom', 'Van'];
        yield 'the lights fitting is a variant' => ['Vehicles_PickUpVanLights', 'PickUpVan'];
        yield 'several suffixes come off at once' => [
            'Vehicles_PickUpVanLights_NoRandom',
            'PickUpVan',
        ];
        yield 'a damaged lit model reduces too' => [
            'Vehicles_PickUpVanLightsSmashedLeft',
            'PickUpVan',
        ];
        yield '"Normal" is a qualifier, not a shell' => ['Vehicles_CarNormal', 'Car'];
        yield 'the singular prefix is accepted' => ['Vehicle_StepVan', 'StepVan'];
        yield 'a model that is only a suffix keeps its name' => ['Lights', 'Lights'];
        // The catalogue hands back a file name, not a bare model name.
        yield 'the file extension is not part of the shell' => ['Vehicles_SUV.fbx', 'SUV'];
        yield 'a variant is still stripped from a file name' => [
            'Vehicles_PickUpVanLights_NoRandom.fbx',
            'PickUpVan',
        ];
    }

    /**
     * The Chevalier D6 and the Dash Bulldriver share
     * vehicle_pickuptruck_mask, so the mask alone would put 45 unrelated
     * liveries in one group.
     */
    public function testTellsTheTwoPickupsApart(): void
    {
        self::assertNotSame(
            SpawnableVehicles::shellOf('Vehicles_PickUpTruck'),
            SpawnableVehicles::shellOf('Vehicles_PickUpVan'),
        );
    }

    public function testKeepsAPickupWithItsOwnLitVariant(): void
    {
        self::assertSame(
            SpawnableVehicles::shellOf('Vehicles_PickUpTruck'),
            SpawnableVehicles::shellOf('Vehicles_PickUpTruckLights_NoRandom'),
        );
    }
}
