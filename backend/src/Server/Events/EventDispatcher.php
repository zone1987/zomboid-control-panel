<?php

declare(strict_types=1);

namespace App\Server\Events;

use App\Entity\GameServer;
use App\Server\Chat\ChatBroadcaster;
use App\Server\Rcon\RconClientInterface;
use App\Server\Rcon\RconCommandFailed;
use App\Server\Rcon\RconException;
use App\Server\Rcon\RconUnreachable;

/**
 * Turns an event action and its inputs into the command that runs it.
 *
 * Building the command is separate from sending it so the shapes can be
 * tested without a server: the argument forms come from the command
 * classes and getting one wrong fails silently in the game.
 */
final readonly class EventDispatcher
{
    public function __construct(private RconClientInterface $rcon)
    {
    }

    /**
     * @param array<string, mixed> $inputs
     *
     * @throws RconException
     */
    public function dispatch(GameServer $server, string $actionId, array $inputs): EventOutcome
    {
        $command = self::commandFor($actionId, $inputs);
        $config = $server->getRconConfig();

        if ($config === null) {
            throw new RconUnreachable('This server has no RCON configuration.');
        }

        return new EventOutcome($actionId, $command, $this->rcon->execute($config, $command));
    }

    /**
     * @param array<string, mixed> $inputs
     *
     * @throws RconCommandFailed when the action is unknown or an input is unusable
     */
    public static function commandFor(string $actionId, array $inputs): string
    {
        $action = EventCatalogue::find($actionId);

        if ($action === null) {
            throw new RconCommandFailed(sprintf('Unknown event action "%s".', $actionId));
        }

        return match ($actionId) {
            'startRain' => sprintf('startrain %d', self::number($action, $inputs, 'intensity')),
            'stopRain' => 'stoprain',
            'startStorm' => sprintf('startstorm %d', self::number($action, $inputs, 'duration')),
            'stopWeather' => 'stopweather',
            'thunder' => self::withOptionalPlayer('thunder', $inputs),
            'lightning' => self::withOptionalPlayer('lightning', $inputs),
            'chopper' => 'chopper',
            'gunshot' => 'gunshot',
            'alarm' => 'alarm',
            'broadcast' => self::broadcast($inputs),
            'spawnVehicle' => self::spawnVehicle($inputs),
            'hordeNearPlayer' => sprintf(
                'createhorde %d "%s"',
                self::number($action, $inputs, 'count'),
                self::player($inputs, 'player'),
            ),
            // createhorde2 is varargs: named flags in any order, no quotes.
            'hordeAtPoint' => sprintf(
                'createhorde2 -count %d -x %d -y %d -z 0 -radius %d',
                self::number($action, $inputs, 'count'),
                self::number($action, $inputs, 'x'),
                self::number($action, $inputs, 'y'),
                self::number($action, $inputs, 'radius'),
            ),
            'removeZombies' => sprintf(
                'removezombies -x %d -y %d -z 0 -radius %d',
                self::number($action, $inputs, 'x'),
                self::number($action, $inputs, 'y'),
                self::number($action, $inputs, 'radius'),
            ),
            default => throw new RconCommandFailed(sprintf('Action "%s" has no command.', $actionId)),
        };
    }

    /** @param array<string, mixed> $inputs */
    private static function broadcast(array $inputs): string
    {
        $message = ChatBroadcaster::sanitise(\is_string($inputs['message'] ?? null) ? $inputs['message'] : '');

        if ($message === '') {
            throw new RconCommandFailed('The message is empty.');
        }

        return sprintf('servermsg "%s"', $message);
    }

    /** @param array<string, mixed> $inputs */
    private static function spawnVehicle(array $inputs): string
    {
        $script = trim(\is_string($inputs['script'] ?? null) ? $inputs['script'] : '');

        if (!VehicleScripts::isValidName($script)) {
            throw new RconCommandFailed(sprintf('"%s" is not a vehicle script name.', $script));
        }

        return sprintf('addvehicle "%s" "%s"', $script, self::player($inputs, 'player'));
    }

    /** @param array<string, mixed> $inputs */
    private static function withOptionalPlayer(string $command, array $inputs): string
    {
        $player = \is_string($inputs['player'] ?? null) ? trim($inputs['player']) : '';

        return $player === '' ? $command : sprintf('%s "%s"', $command, self::clean($player));
    }

    /** @param array<string, mixed> $inputs */
    private static function player(array $inputs, string $name): string
    {
        $value = \is_string($inputs[$name] ?? null) ? self::clean(trim($inputs[$name])) : '';

        if ($value === '') {
            throw new RconCommandFailed(sprintf('This action needs a player for "%s".', $name));
        }

        return $value;
    }

    /** @param array<string, mixed> $inputs */
    private static function number(EventAction $action, array $inputs, string $name): int
    {
        $field = null;

        foreach ($action->fields as $candidate) {
            if ($candidate->name === $name) {
                $field = $candidate;
            }
        }

        $raw = $inputs[$name] ?? $field?->default;
        $value = filter_var($raw, \FILTER_VALIDATE_INT);

        if ($value === false) {
            throw new RconCommandFailed(sprintf('"%s" needs a whole number.', $name));
        }

        if ($field !== null && (($field->min !== null && $value < $field->min) || ($field->max !== null && $value > $field->max))) {
            throw new RconCommandFailed(sprintf(
                '"%s" must be between %d and %d.',
                $name,
                (int) $field->min,
                (int) $field->max,
            ));
        }

        return $value;
    }

    /** Arguments are quoted, so a quote of its own would end one early. */
    private static function clean(string $value): string
    {
        return trim(preg_replace('/["\r\n\x00-\x1f]+/', '', $value) ?? '');
    }
}
