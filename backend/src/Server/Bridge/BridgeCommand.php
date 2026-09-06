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
    case ReleaseClimate = 'releaseClimate';
    case ResetClimate = 'resetClimate';
    case ReleaseSnow = 'releaseSnow';
    case TriggerWeatherStage = 'triggerWeatherStage';
    case GenerateWeather = 'generateWeather';
    case SetSnow = 'setSnow';
    case StartBlizzard = 'startBlizzard';
    case StopWeather = 'stopWeather';
    case ReadClimate = 'readClimate';
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
     * What each climate value accepts, by index.
     *
     * Read from ClimateManager::setup() in build 42: the ClimateFloat
     * constructor sets max to 1 and leaves min at 0, and setup() then
     * overrides three of them. setAdminValue clamps to these, so a value
     * outside them is silently changed rather than refused — which the
     * panel must not let happen unnoticed.
     */
    public const CLIMATE_BOUNDS = [
        4 => [-80.0, 80.0],
        7 => [-1.0, 1.0],
        10 => [0.0, 100.0],
    ];

    /**
     * The weather stages the game can be told to run, by its own name.
     *
     * From WeatherPeriod in build 42, which declares twelve; these are
     * the eight a person would ask for. START, INTERMEZZO, MODDED and
     * KATEBOB_STORM are the simulation's own bookkeeping.
     */
    public const WEATHER_STAGES = [
        'showers',
        'heavyPrecip',
        'storm',
        'clearing',
        'moderate',
        'drizzle',
        'blizzard',
        'tropical',
    ];

    /** The game's own admin panel offers 4 to 240 game hours. */
    public const MAX_STAGE_HOURS = 240;

    /** The one climate boolean the game keeps: BOOL_IS_SNOW. */
    public const CLIMATE_BOOL_IS_SNOW = 0;

    /** The climate colours: COLOR_GLOBAL_LIGHT and COLOR_NEW_FOG. */
    public const CLIMATE_COLOURS = [
        'globalLight' => 0,
        'fog' => 1,
    ];

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, scalar>
     */
    public function validate(array $arguments): array
    {
        return match ($this) {
            self::Ping, self::StopRain, self::StartBlizzard, self::StopWeather,
            self::ReadClimate, self::ResetClimate, self::ReleaseSnow => [],
            self::TriggerWeatherStage => [
                'stage' => self::stage($arguments),
                'duration' => self::number($arguments, 'duration', 1, self::MAX_STAGE_HOURS),
            ],
            self::GenerateWeather => [
                'strength' => self::number($arguments, 'strength', 0.1, 1),
                // Warm unless a cold front is asked for, which is what the
                // game's own generator offers as its only two choices.
                'front' => ($arguments['front'] ?? 'warm') === 'cold' ? 'cold' : 'warm',
            ],
            self::SetSnow => ['snowing' => (bool) ($arguments['snowing'] ?? false)],
            self::SetTime => ['hour' => self::number($arguments, 'hour', 0, 24)],
            self::SetDate => array_filter([
                'day' => isset($arguments['day']) ? self::number($arguments, 'day', 1, 31) : null,
                'month' => isset($arguments['month']) ? self::number($arguments, 'month', 1, 12) : null,
            ], static fn (mixed $value): bool => $value !== null),
            self::StartRain => ['intensity' => self::number($arguments, 'intensity', 0, 100)],
            self::SetClimateValue => self::climateValue($arguments),
            self::ReleaseClimate => ['index' => self::climateIndex($arguments)],
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

    /**
     * An index with a value the game will actually keep.
     *
     * setAdminValue clamps rather than refuses, so a value out of range
     * would be applied as something else and reported as success.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, scalar>
     */
    private static function climateValue(array $arguments): array
    {
        $index = self::climateIndex($arguments);
        [$min, $max] = self::CLIMATE_BOUNDS[$index] ?? [0.0, 1.0];

        return [
            'index' => $index,
            'value' => self::number($arguments, 'value', $min, $max),
        ];
    }

    /** @param array<string, mixed> $arguments */
    private static function stage(array $arguments): string
    {
        $stage = $arguments['stage'] ?? null;

        if (!\is_string($stage) || !\in_array($stage, self::WEATHER_STAGES, true)) {
            throw new InvalidBridgeCommand(sprintf(
                '"stage" must be one of %s.',
                implode(', ', self::WEATHER_STAGES),
            ));
        }

        return $stage;
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
