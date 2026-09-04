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
    /** @return list<EventAction> */
    public static function all(): array
    {
        return [
            ...self::weather(),
            ...self::sounds(),
            ...self::players(),
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
            new EventAction('startRain', EventAction::GROUP_WEATHER, EventAction::CHANNEL_RCON, ['startrain'], [
                EventField::number('intensity', 1, 100, 50),
            ]),
            new EventAction('stopRain', EventAction::GROUP_WEATHER, EventAction::CHANNEL_RCON, ['stoprain']),
            new EventAction('startStorm', EventAction::GROUP_WEATHER, EventAction::CHANNEL_RCON, ['startstorm'], [
                EventField::number('duration', 1, 24, 2),
            ]),
            new EventAction('stopWeather', EventAction::GROUP_WEATHER, EventAction::CHANNEL_RCON, ['stopweather']),
            new EventAction('thunder', EventAction::GROUP_WEATHER, EventAction::CHANNEL_RCON, ['thunder'], [
                EventField::player('player', required: false),
            ]),
            new EventAction('lightning', EventAction::GROUP_WEATHER, EventAction::CHANNEL_RCON, ['lightning'], [
                EventField::player('player', required: false),
            ]),
        ];
    }

    /** @return list<EventAction> */
    private static function sounds(): array
    {
        return [
            new EventAction('chopper', EventAction::GROUP_SOUNDS, EventAction::CHANNEL_RCON, ['chopper']),
            new EventAction('gunshot', EventAction::GROUP_SOUNDS, EventAction::CHANNEL_RCON, ['gunshot']),
            new EventAction('alarm', EventAction::GROUP_SOUNDS, EventAction::CHANNEL_RCON, ['alarm']),
        ];
    }

    /** @return list<EventAction> */
    private static function players(): array
    {
        return [
            new EventAction('broadcast', EventAction::GROUP_PLAYERS, EventAction::CHANNEL_RCON, ['servermsg'], [
                EventField::text('message', 250),
            ]),
            new EventAction('spawnVehicle', EventAction::GROUP_PLAYERS, EventAction::CHANNEL_RCON, ['addvehicle'], [
                EventField::choice('script', VehicleScripts::NAMES),
                EventField::player('player'),
            ]),
            new EventAction('hordeNearPlayer', EventAction::GROUP_PLAYERS, EventAction::CHANNEL_RCON, ['createhorde'], [
                EventField::number('count', 1, 500, 20),
                EventField::player('player'),
            ], destructive: true),
            new EventAction('hordeAtPoint', EventAction::GROUP_PLAYERS, EventAction::CHANNEL_RCON, ['createhorde2'], [
                EventField::number('count', 1, 500, 20),
                EventField::number('x', 0, 20000, 10778),
                EventField::number('y', 0, 20000, 9770),
                EventField::number('radius', 1, 200, 20),
            ], destructive: true),
            new EventAction('removeZombies', EventAction::GROUP_PLAYERS, EventAction::CHANNEL_RCON, ['removezombies'], [
                EventField::number('x', 0, 20000, 10778),
                EventField::number('y', 0, 20000, 9770),
                EventField::number('radius', 1, 500, 50),
            ], destructive: true),
        ];
    }
}
