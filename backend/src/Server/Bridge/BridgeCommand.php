<?php

declare(strict_types=1);

namespace App\Server\Bridge;

use App\Server\Players\Character\CharacterDefinitions;

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
    case ReadClimateColours = 'readClimateColours';
    case SetClimateColour = 'setClimateColour';
    case ReleaseClimateColour = 'releaseClimateColour';
    case StrikeLightning = 'strikeLightning';
    case SetUtility = 'setUtility';
    case ReadUtilities = 'readUtilities';
    case HealPlayer = 'healPlayer';
    case ReadPlayerStats = 'readPlayerStats';
    case SetPlayerStat = 'setPlayerStat';
    /** The game's own ceiling for every skill. */
    public const MAX_SKILL_LEVEL = 10;

    /**
     * A cap on one grant, not on a skill's total.
     *
     * Level 10 in the dearest skill is roughly 200k, so this leaves room
     * to fill one from nothing while still refusing a typo of six zeroes.
     */
    public const MAX_SKILL_XP = 500000;

    /**
     * Every trainable skill, by the perk id the bridge resolves.
     *
     * Generated from the same six categories the interface groups by,
     * which came from `PerkFactory`'s constant pool. Category perks
     * (Combat, Firearm, Crafting, Survivalist, PhysicalCategory,
     * FarmingCategory) are headings and deliberately absent: they have
     * no level to set.
     *
     * @var list<string>
     */
    public const SKILLS = [
        'Axe',
        'Blunt',
        'SmallBlunt',
        'LongBlade',
        'SmallBlade',
        'Spear',
        'Maintenance',
        'Aiming',
        'Reloading',
        'Woodwork',
        'Carving',
        'Cooking',
        'Electricity',
        'Glassmaking',
        'FlintKnapping',
        'Masonry',
        'Blacksmith',
        'Mechanics',
        'Pottery',
        'Tailoring',
        'MetalWelding',
        'Doctor',
        'Fishing',
        'PlantScavenging',
        'Tracking',
        'Trapping',
        'Fitness',
        'Strength',
        'Lightfoot',
        'Nimble',
        'Sprinting',
        'Sneak',
        'Farming',
        'Husbandry',
        'Butchering',
    ];

    case ReadTraits = 'readTraits';
    case ReadSkillDetail = 'readSkillDetail';
    case SetSkillLevel = 'setSkillLevel';
    case AddSkillXp = 'addSkillXp';
    case SetTrait = 'setTrait';
    case SetPlayerWeight = 'setPlayerWeight';
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
            self::ReadClimate, self::ResetClimate, self::ReleaseSnow,
            self::ReadClimateColours, self::ReadUtilities => [],
            self::SetClimateColour => [
                'name' => self::colourName($arguments),
                // Every channel a fraction, because that is what the
                // game's own setAdminValue takes.
                'r' => self::number($arguments, 'r', 0, 1),
                'g' => self::number($arguments, 'g', 0, 1),
                'b' => self::number($arguments, 'b', 0, 1),
                'a' => isset($arguments['a']) ? self::number($arguments, 'a', 0, 1) : 1,
            ],
            self::ReleaseClimateColour => ['name' => self::colourName($arguments)],
            self::StrikeLightning => [
                'x' => self::number($arguments, 'x', 0, 20000),
                'y' => self::number($arguments, 'y', 0, 20000),
                // The three parts separately: a rumble alone is distant
                // thunder, a flash is lightning without damage, and a
                // strike sets fire to what it hits. Only the strike is
                // off by default, because only it destroys anything.
                'strike' => ($arguments['strike'] ?? false) === true,
                'flash' => ($arguments['flash'] ?? true) !== false,
                'rumble' => ($arguments['rumble'] ?? true) !== false,
            ],
            self::SetUtility => [
                'utility' => self::utility($arguments),
                'on' => ($arguments['on'] ?? false) === true,
            ],
            self::HealPlayer, self::ReadPlayerStats => ['player' => self::text($arguments, 'player')],
            self::SetPlayerStat => [
                'player' => self::text($arguments, 'player'),
                'stat' => self::statistic($arguments),
                // Bounds are the game's own and differ per statistic, so
                // the bridge checks them against what the stat reports
                // rather than the panel guessing a range here.
                'value' => self::number($arguments, 'value', -1000, 1000),
            ],
            self::SetPlayerWeight => [
                'player' => self::text($arguments, 'player'),
                'weight' => self::number($arguments, 'weight', 30, 200),
            ],
            self::ReadTraits => ['player' => self::text($arguments, 'player')],
            self::ReadSkillDetail => ['player' => self::text($arguments, 'player')],
            self::SetSkillLevel => [
                'player' => self::text($arguments, 'player'),
                'skill' => self::skill($arguments),
                // Ten is the game's own ceiling, and the bridge refuses
                // anything else rather than clamping it silently.
                'level' => (int) self::number($arguments, 'level', 0, self::MAX_SKILL_LEVEL),
            ],
            self::AddSkillXp => [
                'player' => self::text($arguments, 'player'),
                'skill' => self::skill($arguments),
                'amount' => self::number($arguments, 'amount', 1, self::MAX_SKILL_XP),
                'multiplied' => ($arguments['multiplied'] ?? false) === true,
            ],
            self::SetTrait => [
                'player' => self::text($arguments, 'player'),
                'trait' => self::characterTrait($arguments),
                'adding' => ($arguments['adding'] ?? false) === true,
            ],
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

    /** The two climate colours the game keeps. */
    public const CLIMATE_COLOUR_NAMES = ['globalLight', 'fog'];

    /** The utilities a server can switch. */
    public const UTILITIES = ['power', 'water'];

    /**
     * The twenty-four statistics the game registers, by its own ids.
     *
     * From `CharacterStat`'s constant pool in build 42, not from the
     * constant names — the two agree here, which a test asserts against
     * the bridge rather than trusting.
     */
    public const CHARACTER_STATS = [
        'Anger', 'Boredom', 'Discomfort', 'Endurance', 'Fatigue', 'Fitness',
        'FoodSickness', 'Hunger', 'Idleness', 'Intoxication', 'Morale',
        'NicotineWithdrawal', 'Pain', 'Panic', 'Poison', 'Sanity', 'Sickness',
        'Stress', 'Temperature', 'Thirst', 'Unhappiness', 'Wetness',
        'ZombieFever', 'ZombieInfection',
    ];

    /** @param array<string, mixed> $arguments */
    private static function statistic(array $arguments): string
    {
        $stat = $arguments['stat'] ?? null;

        if (!\is_string($stat) || !\in_array($stat, self::CHARACTER_STATS, true)) {
            throw new InvalidBridgeCommand(sprintf(
                '"stat" must be one of %s.',
                implode(', ', self::CHARACTER_STATS),
            ));
        }

        return $stat;
    }

    /**
     * A skill the game actually has, by its id.
     *
     * The ids are the perks' own, not their display names: the game
     * shows `Woodwork` as "Carpentry" and `PlantScavenging` as
     * "Foraging", so a name-based lookup would miss four of them. The
     * bridge resolves the same ids through `PerkFactory`.
     *
     * @param array<string, mixed> $arguments
     */
    private static function skill(array $arguments): string
    {
        $skill = $arguments['skill'] ?? null;

        if (!\is_string($skill) || !\in_array($skill, self::SKILLS, true)) {
            throw new InvalidBridgeCommand('"skill" must be a skill the game defines.');
        }

        return $skill;
    }

    /**
     * A trait the game actually defines.
     *
     * Checked against the generated table rather than passed through:
     * an unknown name reaches the bridge, fails there, and the operator
     * sees "the game has no trait called x" after a round trip. And a
     * profession trait is refused — those are granted by a job, so
     * setting one by hand leaves the sheet inconsistent with itself.
     *
     * @param array<string, mixed> $arguments
     */
    private static function characterTrait(array $arguments): string
    {
        $trait = $arguments['trait'] ?? null;

        if (!\is_string($trait) || !isset(CharacterDefinitions::TRAITS[$trait])) {
            throw new InvalidBridgeCommand('"trait" must be a trait the game defines.');
        }

        if (CharacterDefinitions::TRAITS[$trait]['professionTrait'] === true) {
            throw new InvalidBridgeCommand(sprintf(
                '"%s" is granted by a profession and cannot be set on its own.',
                $trait,
            ));
        }

        return $trait;
    }

    /** @param array<string, mixed> $arguments */
    private static function colourName(array $arguments): string
    {
        $name = $arguments['name'] ?? null;

        if (!\is_string($name) || !\in_array($name, self::CLIMATE_COLOUR_NAMES, true)) {
            throw new InvalidBridgeCommand(sprintf(
                '"name" must be one of %s.',
                implode(', ', self::CLIMATE_COLOUR_NAMES),
            ));
        }

        return $name;
    }

    /** @param array<string, mixed> $arguments */
    private static function utility(array $arguments): string
    {
        $utility = $arguments['utility'] ?? null;

        if (!\is_string($utility) || !\in_array($utility, self::UTILITIES, true)) {
            throw new InvalidBridgeCommand(sprintf(
                '"utility" must be one of %s.',
                implode(', ', self::UTILITIES),
            ));
        }

        return $utility;
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
