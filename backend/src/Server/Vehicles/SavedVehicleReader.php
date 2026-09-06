<?php

declare(strict_types=1);

namespace App\Server\Vehicles;

/**
 * Reads what BaseVehicle.save() wrote into a vehicles.db row.
 *
 * The layout was read from build 42.20.2's own decompiled source and
 * then verified byte for byte against a real save: IsoMovingObject
 * writes a flag, the class id 33, two offset floats and the
 * coordinates; BaseVehicle follows with the physics height, the
 * rotation quaternion and the script name, then fixed-width state.
 *
 * Parsed forwards rather than by fixed offsets, because the mod-data
 * flag at byte 26 may introduce a variable-length table and shift
 * everything after it.
 *
 * Stops at the part list. Its entries carry nested inventory items and
 * containers of variable length, so paint colour and rust -- which sit
 * behind it -- are left to the bridge, which can simply call
 * getColorHue() on the live object.
 */
final class SavedVehicleReader
{
    /** IsoObject.factoryGetClassID("Vehicle"). */
    private const VEHICLE_CLASS_ID = 33;

    /** The world version this layout was read from. */
    public const KNOWN_WORLD_VERSION = 249;

    private const MAX_SCRIPT_LENGTH = 128;

    /**
     * Null when the bytes do not describe a vehicle, so one unreadable
     * row cannot cost the whole map.
     */
    public function read(int $id, float $x, float $y, string $data): ?VehicleRecord
    {
        // Byte 1 says what this row holds; anything else is not a
        // vehicle and must not be interpreted as one.
        if (\strlen($data) < 28 || \ord($data[1]) !== self::VEHICLE_CLASS_ID) {
            return null;
        }

        $z = self::floatAt($data, 18);
        $cursor = 26;

        // A non-empty Lua table would follow the flag, and its length is
        // not knowable here.
        if (\ord($data[$cursor]) !== 0) {
            return null;
        }

        // The physics height, then the rotation as four floats.
        ++$cursor;
        $heading = self::headingAt($data, $cursor + 4);
        $cursor += 4 + 16;

        $script = self::stringAt($data, $cursor);

        if ($script === null || preg_match('/^[\w.\-]+$/', $script) !== 1) {
            return null;
        }

        $cursor += 2 + \strlen($script);

        // BaseVehicle writes the two maxima first, then the two
        // current values -- front, rear, currentFront, currentRear.
        /** @var array{skin: int, engine: int, frontMax: int, rearMax: int, front: int, rear: int}|null $state */
        $state = self::unpack(
            'Nskin/Cengine/NfrontMax/NrearMax/Nfront/Nrear',
            $data,
            $cursor,
        );

        if ($state === null) {
            return null;
        }

        return new VehicleRecord(
            id: $id,
            script: $script,
            // The columns hold the same coordinates as the blob and are
            // what the game itself queries by.
            x: $x,
            y: $y,
            z: $z === null ? 0 : (int) round($z),
            heading: $heading,
            skin: $state['skin'],
            engineRunning: $state['engine'] === 1,
            condition: new VehicleCondition(
                frontMax: $state['frontMax'],
                front: $state['front'],
                rearMax: $state['rearMax'],
                rear: $state['rear'],
            ),
        );
    }

    /**
     * The way the vehicle faces, in degrees clockwise from north.
     *
     * Stored as a quaternion from the physics transform, whose vertical
     * axis is y -- so rotation about y is the heading, which is what
     * the game's own getAngleY() converts.
     *
     * Taken as it stands, with no offset. A vehicle whose rotation is
     * the identity quaternion has not been turned at all and must read
     * as zero -- verified against the taxi in a real save, whose row
     * carries the identity and which stands unturned in the game.
     *
     * The row's own eight-way direction field looks half a circle out
     * against this, and an offset to match it was tried and reverted:
     * that field is IsoDirections, whose ordinal 0 is the game's north
     * rather than the model's front, so the two are not the same
     * measurement and agreeing with it would have put every vehicle
     * back to front.
     */
    private static function headingAt(string $data, int $offset): ?float
    {
        /** @var array{x: float, y: float, z: float, w: float}|null $q */
        $q = self::unpack('Gx/Gy/Gz/Gw', $data, $offset);

        if ($q === null) {
            return null;
        }

        // A rotation is a unit quaternion; anything else is not one.
        $length = sqrt($q['x'] ** 2 + $q['y'] ** 2 + $q['z'] ** 2 + $q['w'] ** 2);

        if (abs($length - 1.0) > 0.01) {
            return null;
        }

        return fmod(rad2deg(2 * atan2($q['y'], $q['w'])) + 360.0, 360.0);
    }

    /** A two-byte length, then that many bytes of UTF-8. */
    private static function stringAt(string $data, int $offset): ?string
    {
        /** @var array{length: int}|null $header */
        $header = self::unpack('nlength', $data, $offset);

        if ($header === null || $header['length'] < 1 || $header['length'] > self::MAX_SCRIPT_LENGTH) {
            return null;
        }

        $text = substr($data, $offset + 2, $header['length']);

        return \strlen($text) === $header['length'] ? $text : null;
    }

    private static function floatAt(string $data, int $offset): ?float
    {
        /** @var array{value: float}|null $read */
        $read = self::unpack('Gvalue', $data, $offset);

        return $read === null ? null : $read['value'];
    }

    /**
     * unpack() past the end of a string is a warning and a false, so
     * the room is checked first.
     *
     * @return array<string, int|float>|null
     */
    private static function unpack(string $format, string $data, int $offset): ?array
    {
        if ($offset < 0 || $offset >= \strlen($data)) {
            return null;
        }

        $read = @unpack($format, $data, $offset);

        return $read === false ? null : $read;
    }
}
