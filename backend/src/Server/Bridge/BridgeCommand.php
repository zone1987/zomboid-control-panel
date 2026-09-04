<?php

declare(strict_types=1);

namespace App\Server\Bridge;

/**
 * Something the panel asks the running server to do.
 *
 * These are the things RCON cannot express: the climate values, the
 * in-game clock, a sound at a point, a safehouse setting. Anything RCON
 * covers stays on RCON, which is immediate and needs no queue.
 */
enum BridgeCommand: string
{
    case Ping = 'ping';
    case SetTime = 'setTime';
    case SetDate = 'setDate';
    case StartRain = 'startRain';
    case StopRain = 'stopRain';
    case SetClimateValue = 'setClimateValue';
    case PlaySound = 'playSound';
    case SetSafehouseRespawn = 'setSafehouseRespawn';

    /**
     * What is actually on the ground around a point, right now.
     *
     * The one thing rendered tiles can never show: they are a picture of
     * the world as it shipped, and this is the world as it is.
     */
    case ReadSurroundings = 'readSurroundings';

    /**
     * The climate values the game keeps, by their index.
     *
     * Taken from ClimateManager in build 42. The panel offers them by
     * name; the bridge sets them by index, because the names are not
     * exposed to Lua.
     */
    public const CLIMATE_VALUES = [
        'desaturation' => 0,
        'globalLight' => 1,
        'nightStrength' => 2,
        'precipitation' => 3,
        'temperature' => 4,
        'fog' => 5,
        'wind' => 6,
        'windAngle' => 7,
        'clouds' => 8,
        'ambient' => 9,
        'viewDistance' => 10,
        'daylight' => 11,
        'humidity' => 12,
    ];

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, scalar>
     */
    public function validate(array $arguments): array
    {
        return match ($this) {
            self::Ping, self::StopRain => [],
            self::SetTime => ['hour' => self::number($arguments, 'hour', 0, 24)],
            self::SetDate => array_filter([
                'day' => isset($arguments['day']) ? self::number($arguments, 'day', 1, 31) : null,
                'month' => isset($arguments['month']) ? self::number($arguments, 'month', 1, 12) : null,
            ], static fn (mixed $value): bool => $value !== null),
            self::StartRain => ['intensity' => self::number($arguments, 'intensity', 0, 100)],
            self::SetClimateValue => [
                'index' => self::climateIndex($arguments),
                'value' => self::number($arguments, 'value', -100, 100),
            ],
            self::PlaySound => self::sound($arguments),
            self::SetSafehouseRespawn => [
                'title' => self::text($arguments, 'title'),
                'enabled' => (bool) ($arguments['enabled'] ?? false),
            ],
            self::ReadSurroundings => self::surroundings($arguments),
        };
    }

    /**
     * Where to look, and how far.
     *
     * The radius is capped hard: every square is an iteration inside the
     * server's own loop, and a radius of 20 is already 1600 of them.
     * Nobody should be able to ask for a whole cell by typing a bigger
     * number.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, scalar>
     */
    public const MAX_RADIUS = 20;

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, scalar>
     */
    private static function surroundings(array $arguments): array
    {
        $radius = [
            'radius' => self::number($arguments, 'radius', 1, self::MAX_RADIUS),
            // Off by default: a glance wants a summary, a stocktake
            // wants every item in every crate.
            'fullContents' => ($arguments['fullContents'] ?? false) === true,
        ];

        // Around a player, or around a point on the map.
        if (isset($arguments['x'], $arguments['y'])) {
            return [
                ...$radius,
                'x' => self::number($arguments, 'x', 0, 20000),
                'y' => self::number($arguments, 'y', 0, 20000),
                'z' => isset($arguments['z']) ? self::number($arguments, 'z', -1, 7) : 0,
            ];
        }

        return [...$radius, 'player' => self::text($arguments, 'player')];
    }

    /** @param array<string, mixed> $arguments */
    private static function climateIndex(array $arguments): int
    {
        $named = $arguments['name'] ?? null;

        if (\is_string($named)) {
            if (!isset(self::CLIMATE_VALUES[$named])) {
                throw new InvalidBridgeCommand(sprintf('There is no climate value called "%s".', $named));
            }

            return self::CLIMATE_VALUES[$named];
        }

        return (int) self::number($arguments, 'index', 0, 12);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, scalar>
     */
    private static function sound(array $arguments): array
    {
        $sound = [
            'radius' => self::number($arguments, 'radius', 1, 500),
            'volume' => self::number($arguments, 'volume', 1, 500),
        ];

        // Either a point in the world, or a player to place it on.
        if (isset($arguments['x'], $arguments['y'])) {
            return [
                ...$sound,
                'x' => self::number($arguments, 'x', 0, 20000),
                'y' => self::number($arguments, 'y', 0, 20000),
                'z' => isset($arguments['z']) ? self::number($arguments, 'z', -1, 7) : 0,
            ];
        }

        return [...$sound, 'player' => self::text($arguments, 'player')];
    }

    /** @param array<string, mixed> $arguments */
    private static function number(array $arguments, string $name, float $min, float $max): float|int
    {
        $value = $arguments[$name] ?? null;

        if (!is_numeric($value)) {
            throw new InvalidBridgeCommand(sprintf('"%s" needs a number.', $name));
        }

        $value = $value + 0;

        if ($value < $min || $value > $max) {
            throw new InvalidBridgeCommand(sprintf('"%s" must be between %s and %s.', $name, $min, $max));
        }

        return $value;
    }

    /** @param array<string, mixed> $arguments */
    private static function text(array $arguments, string $name): string
    {
        $value = $arguments[$name] ?? null;

        if (!\is_string($value) || trim($value) === '') {
            throw new InvalidBridgeCommand(sprintf('"%s" is required.', $name));
        }

        return trim($value);
    }
}
