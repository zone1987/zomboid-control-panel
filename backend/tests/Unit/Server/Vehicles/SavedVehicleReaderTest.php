<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Vehicles;

use App\Server\Vehicles\SavedVehicleReader;
use PHPUnit\Framework\TestCase;

/**
 * The bytes below are real rows from a live server's vehicles.db, world
 * version 249. Synthesising them would only prove the reader agrees
 * with itself.
 */
final class SavedVehicleReaderTest extends TestCase
{
    /** A pickup van, turned a quarter circle from its model's own front. */
    private const VAN = '0121428000004340000046293a1f461cd2000000000000000002003ef22f6c000000'
        .'00bf3504f3000000003f3504f3000e426173652e5069636b557056616e00'
        .'0000000000000064000000640000006400000064';

    /** A small car, turned half a circle. */
    private const SMALL_CAR = '0121428000004340000046279e00461800b80000000000000000003e644d000000'
        .'00003f8000000000000000000000000d426173652e536d616c6c436172'
        .'000000000000000064000000640000006400000064';

    /** Standing at an angle no eight-way direction can express. */
    private const MODERN_CAR = '01214280000043400000462856664617ae000000000000000006003eed0f6300000000'
        .'3f3504f5000000003f3504f1000e426173652e4d6f6465726e43617200'
        .'0000000000000064000000640000006400000064';

    /**
     * The van again, with all four durabilities made distinct, so the
     * order of the fields is actually under test: the game writes the
     * two maxima before the two current values.
     */
    private const DENTED_VAN = '0121428000004340000046293a1f461cd2000000000000000002003ef22f6c00000000'
        .'bf3504f3000000003f3504f3000e426173652e5069636b557056616e00'
        .'000000000000005000000046000000190000003c';

    private static function bytes(string $hex): string
    {
        return (string) hex2bin(str_replace(' ', '', $hex));
    }

    private static function read(string $hex, int $id = 1, float $x = 0, float $y = 0): ?object
    {
        return (new SavedVehicleReader())->read($id, $x, $y, self::bytes($hex));
    }

    public function testReadsTheScriptNameTheGameWrote(): void
    {
        self::assertSame('Base.PickUpVan', self::read(self::VAN)?->script);
        self::assertSame('Base.SmallCar', self::read(self::SMALL_CAR)?->script);
        self::assertSame('Base.ModernCar', self::read(self::MODERN_CAR)?->script);
    }

    public function testKeepsThePositionTheRowCarries(): void
    {
        $vehicle = self::read(self::VAN, 1, 10830.53, 10036.5);

        self::assertNotNull($vehicle);
        self::assertSame(10831, $vehicle->toArray()['x']);
        self::assertSame(10037, $vehicle->toArray()['y']);
        self::assertSame(0, $vehicle->z);
    }

    /**
     * The heading is the rotation quaternion turned into degrees, taken
     * as it stands. The row's own eight-way field is IsoDirections,
     * measured from the game's north rather than the model's front, so
     * the two do not agree and are not meant to.
     */
    public function testReadsTheHeadingFromTheRotationQuaternion(): void
    {
        // The van is turned a quarter circle; the small car sits at the
        // half turn its own quaternion records.
        self::assertSame(270.0, self::read(self::VAN)?->heading);
        self::assertSame(180.0, self::read(self::SMALL_CAR)?->heading);
    }

    /**
     * The anchor: a vehicle whose rotation is the identity quaternion
     * has not been turned at all, so it must read as zero. Verified
     * against the taxi in a real save, which carries the identity and
     * stands unturned in the game.
     */
    public function testAnUnrotatedVehicleFacesZero(): void
    {
        $identity = substr_replace(
            self::bytes(self::VAN),
            pack('GGGG', 0.0, 0.0, 0.0, 1.0),
            31,
            16,
        );

        self::assertSame(0.0, (new SavedVehicleReader())->read(1, 0, 0, $identity)?->heading);
    }

    public function testReadsAnAngleTheEightWayDirectionCannotExpress(): void
    {
        $heading = self::read(self::MODERN_CAR)?->heading;

        self::assertNotNull($heading);
        self::assertGreaterThan(89.0, $heading);
        self::assertLessThan(91.0, $heading);
        self::assertNotSame(90.0, $heading);
    }

    public function testReadsAnUndamagedVehicleAsUndamaged(): void
    {
        $condition = self::read(self::VAN)?->condition;

        self::assertNotNull($condition);
        self::assertFalse($condition->isDamaged());
        self::assertFalse($condition->isWrecked());
        self::assertSame(1.0, $condition->intact());
    }

    /** Front and rear must not be swapped, or the map shows the dent on the wrong end. */
    public function testTellsFrontDamageFromRearDamage(): void
    {
        $condition = self::read(self::DENTED_VAN)?->condition;

        self::assertNotNull($condition);
        self::assertSame(80, $condition->frontMax);
        self::assertSame(25, $condition->front);
        self::assertSame(70, $condition->rearMax);
        self::assertSame(60, $condition->rear);
        self::assertTrue($condition->isDamaged());
        self::assertFalse($condition->isWrecked());
        self::assertSame(0.567, round($condition->intact(), 3));
    }

    public function testReadsTheSkinTheVehicleWears(): void
    {
        self::assertSame(0, self::read(self::VAN)?->skin);
    }

    public function testRefusesABlobThatIsTooShortToHoldAVehicle(): void
    {
        self::assertNull(self::read(''));
        self::assertNull((new SavedVehicleReader())->read(1, 0, 0, str_repeat("\0", 20)));
    }

    /** Byte 1 says what the row holds; 33 is a vehicle and nothing else is. */
    public function testRefusesARowThatIsNotAVehicle(): void
    {
        $notAVehicle = substr_replace(self::bytes(self::VAN), \chr(7), 1, 1);

        self::assertNull((new SavedVehicleReader())->read(1, 0, 0, $notAVehicle));
    }

    /**
     * A mod-data table would sit between the coordinates and the
     * rotation, and its length is not knowable here, so such a row is
     * refused rather than misread.
     */
    public function testRefusesARowCarryingAModDataTable(): void
    {
        $withTable = substr_replace(self::bytes(self::VAN), \chr(1), 26, 1);

        self::assertNull((new SavedVehicleReader())->read(1, 0, 0, $withTable));
    }

    /** A rotation that is not a unit quaternion is not a rotation. */
    public function testReportsNoHeadingRatherThanANonsenseOne(): void
    {
        $broken = substr_replace(self::bytes(self::VAN), pack('GGGG', 5.0, 5.0, 5.0, 5.0), 31, 16);
        $vehicle = (new SavedVehicleReader())->read(1, 0, 0, $broken);

        self::assertNotNull($vehicle, 'A bad rotation must not cost the whole vehicle.');
        self::assertNull($vehicle->heading);
    }
}
