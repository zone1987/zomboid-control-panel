<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Vehicles;

use App\Server\Vehicles\Models\VehicleCatalogue;
use App\Server\Vehicles\Models\VehicleSpecs;
use App\Server\Vehicles\VehicleTypes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VehicleTypesTest extends TestCase
{
    #[DataProvider('vehicles')]
    public function testSortsAVehicleIntoItsType(string $script, string $expected): void
    {
        self::assertSame($expected, VehicleTypes::of($script, VehicleCatalogue::find($script)));
    }

    /** @return iterable<string, array{string, string}> */
    public static function vehicles(): iterable
    {
        yield 'a saloon is a car' => ['Base.CarNormal', VehicleTypes::CAR];
        yield 'a taxi is a car' => ['Base.CarTaxi', VehicleTypes::CAR];
        yield 'a small car is its own type' => ['Base.SmallCar', VehicleTypes::SMALL];
        yield 'a sports car is sporting' => ['Base.SportsCar', VehicleTypes::SPORTS];
        yield 'so is a luxury car' => ['Base.CarLuxury', VehicleTypes::SPORTS];
        yield 'an SUV is off-road' => ['Base.SUV', VehicleTypes::SUV];
        yield 'a pickup is a pickup' => ['Base.PickUpTruck', VehicleTypes::PICKUP];
        yield 'a van is a van' => ['Base.Van', VehicleTypes::VAN];
        yield 'a step van is a van' => ['Base.StepVan', VehicleTypes::VAN];
        yield 'a trailer is a trailer' => ['Base.Trailer', VehicleTypes::TRAILER];

        // The livery decides, not the shell: a cruiser is a saloon with
        // different paint, and an operator looking for one thinks
        // "service vehicle" rather than "car".
        yield 'a police cruiser is a service vehicle' => [
            'Base.CarLightsPolice',
            VehicleTypes::SERVICE,
        ];
        yield 'so is a fire pickup' => ['Base.PickUpTruckLightsFire', VehicleTypes::SERVICE];
        yield 'so is an ambulance' => ['Base.VanAmbulance', VehicleTypes::SERVICE];

        // No paint mask means a burnt-out hull, whatever it used to be.
        yield 'a burnt car is a wreck' => ['Base.CarNormalBurnt', VehicleTypes::WRECK];
        yield 'a burnt van is a wreck' => ['Base.VanBurnt', VehicleTypes::WRECK];
    }

    public function testSortsEveryVehicleInTheCatalogue(): void
    {
        foreach (VehicleCatalogue::scripts() as $script) {
            $type = VehicleTypes::of($script, VehicleCatalogue::find($script));

            self::assertContains($type, VehicleTypes::ORDER, $script);
        }
    }

    /** A filter offering a type nothing falls into is a dead control. */
    public function testEveryOfferedTypeHoldsAVehicle(): void
    {
        $seen = [];

        foreach (VehicleCatalogue::scripts() as $script) {
            $seen[VehicleTypes::of($script, VehicleCatalogue::find($script))] = true;
        }

        foreach (VehicleTypes::ORDER as $type) {
            self::assertArrayHasKey($type, $seen, $type);
        }
    }

    public function testKnowsTheSpecificationsOfEveryVehicle(): void
    {
        foreach (VehicleCatalogue::scripts() as $script) {
            self::assertNotNull(VehicleSpecs::of($script), $script);
        }
    }

    /**
     * The values a person reads off the panel, checked against the game:
     * the step van has the largest bed, the sports cars are fastest.
     */
    public function testReportsTheValuesTheScriptsState(): void
    {
        $stepVan = VehicleSpecs::of('Base.StepVan');
        self::assertSame(160, $stepVan['trunk']);
        self::assertSame(2, $stepVan['seats']);

        $saloon = VehicleSpecs::of('Base.CarNormal');
        self::assertSame(4, $saloon['seats']);
        self::assertSame(5, $saloon['gloveBox']);
        self::assertSame(90, $saloon['maxSpeed']);

        self::assertSame(120, VehicleSpecs::of('Base.SportsCar')['maxSpeed']);
        self::assertSame(6, VehicleSpecs::of('Base.VanSeats')['seats']);
    }
}
