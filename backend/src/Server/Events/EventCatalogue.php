<?php

declare(strict_types=1);

namespace App\Server\Events;

/**
 * Every world-changing action the event console offers.
 *
 * The argument shapes come from the command classes in build 42, which
 * the panel's command reference records: startrain and startstorm take
 * one optional number, thunder and lightning an optional username,
 * gunshot and alarm nothing at all, and createhorde2 and removezombies
 * are varargs taking -x/-y/-z/-radius/-count. That last pair is why
 * hordes are RCON here rather than waiting for the bridge.
 */
final readonly class EventCatalogue
{
    /**
     * The game's own wind ceiling, from getMaxWindspeedKph(), which the
     * bridge reports as maxWindSpeed and which measured 120 on the live
     * server.
     */
    public const MAX_WIND_KPH = 120;

    /** @return list<EventAction> */
    public static function all(): array
    {
        return [
            ...self::weather(),
            ...self::sounds(),
            ...self::players(),
            ...self::world(),
        ];
    }

    public static function find(string $id): ?EventAction
    {
        foreach (self::all() as $action) {
            if ($action->id === $id) {
                return $action;
            }
        }

        return null;
    }

    /** @return list<EventAction> */
    private static function weather(): array
    {
        return [
            new EventAction('startRain', EventAction::CATEGORY_WEATHER, EventAction::CHANNEL_PREFERRED, ['startrain'], [
                EventField::number('intensity', 1, 100, 50),
            ]),
            new EventAction('stopRain', EventAction::CATEGORY_WEATHER, EventAction::CHANNEL_PREFERRED, ['stoprain']),
            new EventAction('startStorm', EventAction::CATEGORY_WEATHER, EventAction::CHANNEL_RCON, ['startstorm'], [
                EventField::number('duration', 1, 24, 2),
            ]),
            new EventAction('stopWeather', EventAction::CATEGORY_WEATHER, EventAction::CHANNEL_RCON, ['stopweather']),
            new EventAction('thunder', EventAction::CATEGORY_SOUNDS, EventAction::CHANNEL_RCON, ['thunder'], [
                EventField::player('player', required: false),
            ]),
            new EventAction('lightning', EventAction::CATEGORY_ACTIONS, EventAction::CHANNEL_RCON, ['lightning'], [
                EventField::player('player', required: false),
            ]),
        ];
    }

    /** @return list<EventAction> */
    private static function sounds(): array
    {
        return [
            new EventAction('chopper', EventAction::CATEGORY_ACTIONS, EventAction::CHANNEL_RCON, ['chopper']),
            new EventAction('gunshot', EventAction::CATEGORY_SOUNDS, EventAction::CHANNEL_RCON, ['gunshot']),
            new EventAction('alarm', EventAction::CATEGORY_SOUNDS, EventAction::CHANNEL_RCON, ['alarm']),
        ];
    }

    /**
     * The bridge half: things RCON has no command for.
     *
     * These travel through the command queue, so they need a bridge of
     * 0.8.0 or newer and are offered greyed out when it is not
     * answering.
     *
     * @return list<EventAction>
     */
    private static function world(): array
    {
        return [
            new EventAction('setTime', EventAction::CATEGORY_WORLD, EventAction::CHANNEL_BRIDGE, [], [
                EventField::number('hour', 0, 24, 12),
            ]),
            new EventAction('setDate', EventAction::CATEGORY_WORLD, EventAction::CHANNEL_BRIDGE, [], [
                EventField::number('day', 1, 31, 1),
                EventField::number('month', 1, 12, 7),
            ]),
            new EventAction('setFog', EventAction::CATEGORY_WEATHER, EventAction::CHANNEL_BRIDGE, [], [
                EventField::number('value', 0, 100, 50),
            ]),
            // In km/h rather than the climate value's 0..100, because the
            // panel displays km/h: a control reading 100 while the strip
            // beside it reads 120 is a control nobody can trust. The
            // ceiling is the game's own getMaxWindspeedKph().
            new EventAction('setWind', EventAction::CATEGORY_WEATHER, EventAction::CHANNEL_BRIDGE, [], [
                EventField::number('value', 0, self::MAX_WIND_KPH, 40),
            ]),
            new EventAction('setTemperature', EventAction::CATEGORY_WEATHER, EventAction::CHANNEL_BRIDGE, [], [
                EventField::number('value', -30, 40, 20),
            ]),
            new EventAction('setClouds', EventAction::CATEGORY_WEATHER, EventAction::CHANNEL_BRIDGE, [], [
                EventField::number('value', 0, 100, 50),
            ]),
            new EventAction('setDaylight', EventAction::CATEGORY_WORLD, EventAction::CHANNEL_BRIDGE, [], [
                EventField::number('value', 0, 100, 100),
            ]),
            new EventAction('setViewDistance', EventAction::CATEGORY_WORLD, EventAction::CHANNEL_BRIDGE, [], [
                EventField::number('value', 0, 100, 50),
            ]),
            new EventAction('soundAtPlayer', EventAction::CATEGORY_SOUNDS, EventAction::CHANNEL_BRIDGE, [], [
                EventField::player('player'),
                EventField::number('radius', 1, 500, 100),
                EventField::number('volume', 1, 500, 100),
            ]),
            new EventAction('soundAtPoint', EventAction::CATEGORY_SOUNDS, EventAction::CHANNEL_BRIDGE, [], [
                EventField::number('x', 0, 20000, 10778),
                EventField::number('y', 0, 20000, 9770),
                EventField::number('radius', 1, 500, 100),
                EventField::number('volume', 1, 500, 100),
            ]),
        ];
    }

    /** @return list<EventAction> */
    private static function players(): array
    {
        return [
            new EventAction('broadcast', EventAction::CATEGORY_ACTIONS, EventAction::CHANNEL_RCON, ['servermsg'], [
                EventField::text('message', 250),
            ]),
            new EventAction('hordeNearPlayer', EventAction::CATEGORY_ZOMBIES, EventAction::CHANNEL_RCON, ['createhorde'], [
                EventField::number('count', 1, 500, 20),
                EventField::player('player'),
            ], destructive: true),
            new EventAction('hordeAtPoint', EventAction::CATEGORY_ZOMBIES, EventAction::CHANNEL_RCON, ['createhorde2'], [
                EventField::number('count', 1, 500, 20),
                EventField::number('x', 0, 20000, 10778),
                EventField::number('y', 0, 20000, 9770),
                EventField::number('radius', 1, 200, 20),
            ], destructive: true),
            new EventAction('removeZombies', EventAction::CATEGORY_ZOMBIES, EventAction::CHANNEL_RCON, ['removezombies'], [
                EventField::number('x', 0, 20000, 10778),
                EventField::number('y', 0, 20000, 9770),
                EventField::number('radius', 1, 500, 50),
            ], destructive: true),
        ];
    }
}
