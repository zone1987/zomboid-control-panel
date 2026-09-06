<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Vehicles\Models;

use App\Server\Vehicles\Models\VehicleCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue is generated from the game's own vehicle scripts. These
 * check the shape it has to keep: a mismatch between a script name and
 * its model shows up as a vehicle that silently will not draw.
 */
final class VehicleCatalogueTest extends TestCase
{
    public function testFindsAVehicleByTheNameThePanelReceives(): void
    {
        // The panel gets "Base.PickUpVan"; the scripts name it "PickUpVan".
        $entry = VehicleCatalogue::find('Base.PickUpVan');

        self::assertNotNull($entry);
        self::assertSame('Vehicles_PickUpVan.fbx', $entry['model']);
        self::assertSame('vehicle_pickupshell.png', $entry['texture']);
    }

    public function testFindsAVehicleByItsBareScriptNameToo(): void
    {
        self::assertSame(
            VehicleCatalogue::find('Base.CarNormal'),
            VehicleCatalogue::find('CarNormal'),
        );
    }

    /**
     * Most vehicles declare only their model and inherit the shell
     * texture from a template. Resolving that is the whole reason this
     * is generated rather than read at runtime.
     */
    public function testResolvesATextureInheritedFromATemplate(): void
    {
        $entry = VehicleCatalogue::find('Base.CarNormal');

        self::assertNotNull($entry);
        self::assertSame('vehicle_carnormalshell.png', $entry['texture']);
        self::assertSame('vehicle_carnormal_mask.png', $entry['mask']);
    }

    /** A burnt-out shell carries no paint, so it has no mask. */
    public function testABurntVehicleHasNoPaintMask(): void
    {
        $entry = VehicleCatalogue::find('Base.CarNormalBurnt');

        self::assertNotNull($entry);
        self::assertSame('Vehicles_CarNormal_Burnt.fbx', $entry['model']);
        self::assertNull($entry['mask']);
    }

    public function testReportsNothingForAVehicleNoScriptDeclares(): void
    {
        self::assertNull(VehicleCatalogue::find('Base.SomeModsOwnCar'));
        self::assertNull(VehicleCatalogue::find(''));
    }

    public function testEveryEntryNamesAModelFileAndAScale(): void
    {
        $scripts = VehicleCatalogue::scripts();

        self::assertGreaterThan(200, \count($scripts), 'The catalogue looks truncated.');

        foreach ($scripts as $script) {
            $entry = VehicleCatalogue::find($script);

            self::assertNotNull($entry, sprintf('"%s" is listed but does not resolve.', $script));
            self::assertStringEndsWith('.fbx', $entry['model']);
            self::assertGreaterThan(0, $entry['scale']);
        }
    }

    /**
     * The size is the model's own measured extent, scaled by the
     * script's `scale`, not the `extents` field.
     *
     * `extents` is the physics collision box, and measured against the
     * real bodies it runs anywhere from 15 % small to 15 % large -- so
     * it cannot be corrected by a factor and is only a fallback for the
     * vehicles that ship no model file.
     */
    public function testCarriesTheSizeTheModelReallyHas(): void
    {
        $car = VehicleCatalogue::find('Base.CarNormal');

        self::assertNotNull($car);
        // Vehicles_CarNormal spans 327.08 model units, at scale 1.82.
        self::assertEqualsWithDelta(5.953, $car['length'], 0.01);
        self::assertEqualsWithDelta(2.417, $car['width'], 0.01);
        // Half a metre longer than the collision box says, which is why
        // the box is not used for drawing.
        self::assertGreaterThan(5.21, $car['length']);
    }

    public function testEverySizeIsThatOfAVehicleRatherThanASymbol(): void
    {
        foreach (VehicleCatalogue::scripts() as $script) {
            $entry = VehicleCatalogue::find($script);

            self::assertNotNull($entry);
            // Nothing in the game is a metre or twenty metres long.
            self::assertGreaterThan(1.5, $entry['length'], $script);
            self::assertLessThan(20.0, $entry['length'], $script);
            self::assertGreaterThan(0.5, $entry['width'], $script);
            self::assertLessThan(5.0, $entry['width'], $script);
            self::assertGreaterThan($entry['width'], $entry['length'], $script);
        }
    }

    /** The wheels are a mesh of their own, placed at declared offsets. */
    public function testCarriesTheWheelPositionsForAnOrdinaryCar(): void
    {
        $car = VehicleCatalogue::find('Base.CarNormal');

        self::assertNotNull($car);
        self::assertCount(4, $car['wheels']);
        self::assertSame('Vehicles_Wheel.txt', $car['wheelMesh']);

        // Front wheels sit toward the model's own front, which is +z.
        $z = array_column($car['wheels'], 'z');
        self::assertGreaterThan(0, max($z));
        self::assertLessThan(0, min($z));

        // And they are mirrored across the centre line.
        $x = array_column($car['wheels'], 'x');
        self::assertSame(0.0, round(array_sum($x), 6));
    }

    /** A file name that could leave the store must never be produced. */
    public function testNoEntryNamesAPathRatherThanAFile(): void
    {
        foreach (VehicleCatalogue::scripts() as $script) {
            $entry = VehicleCatalogue::find($script);

            foreach ([$entry['model'], $entry['texture'], $entry['mask']] as $file) {
                if ($file === null) {
                    continue;
                }

                self::assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+$/', $file);
                self::assertStringNotContainsString('..', $file);
            }
        }
    }
}
