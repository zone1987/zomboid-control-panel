<?php

declare(strict_types=1);

namespace App\Server\Events;

use App\Entity\GameServer;
use App\Entity\ModerationAction;

/**
 * Something that happened, in a shape a notifier can render.
 *
 * One stream serves the notification bell and Discord, because building
 * it twice would mean maintaining it twice — and the two want the same
 * facts in the same order.
 *
 * Deliberately **not** an entity. `ModerationAction` is the record of
 * what somebody did and it is already persisted; this is the message
 * about it, and a message that outlives its delivery is a queue, which
 * Messenger already provides.
 *
 * `subject` exists because `ModerationAction::username` is not always a
 * player: it also holds an action name (`EventController`), a command
 * verb (`ConsoleController`) and a bare constant (`VehicleSpawn`). A
 * template writing "kicked {player}" over a command verb produces
 * nonsense, so what the event is *about* is named explicitly.
 */
final readonly class PanelEvent
{
    /**
     * @param string                    $type    the event kind, e.g. `moderation.kick`
     * @param string|null               $subject who or what it concerns, when that is a player
     * @param array<string, scalar|null> $tokens  what a message template may substitute
     */
    public function __construct(
        public string $type,
        public string $serverId,
        public string $serverName,
        public ?string $subject,
        public ?string $actor,
        public array $tokens,
        public \DateTimeImmutable $at,
        /** True when the server refused it; null when nobody checked. */
        public ?bool $failed = null,
    ) {
    }

    /**
     * The event for one recorded administrative action.
     *
     * The type is prefixed so a subscriber can match a family
     * (`moderation.*`) without knowing all eighteen action names.
     */
    public static function ofModeration(ModerationAction $action): self
    {
        $server = $action->getServer();
        $performer = $action->getPerformedBy();

        return new self(
            'moderation.'.$action->getAction(),
            $server->getId()->toRfc4122(),
            $server->getName(),
            self::subjectOf($action),
            $performer?->getDisplayName() ?? $action->getReason(),
            self::tokensOf($action),
            $action->getPerformedAt(),
            $action->hasFailed(),
        );
    }

    /**
     * Whether this action's `username` names a player.
     *
     * The join and leave rows do, and so does every action taken on
     * somebody. The world and console ones do not: their username field
     * carries what was done rather than to whom.
     */
    private static function subjectOf(ModerationAction $action): ?string
    {
        $username = $action->getUsername();

        return $username === '' ? null : $username;
    }

    /** @return array<string, scalar|null> */
    private static function tokensOf(ModerationAction $action): array
    {
        $server = $action->getServer();
        $tokens = [
            'server' => $server->getName(),
            'player' => $action->getUsername(),
            'admin' => $action->getPerformedBy()?->getDisplayName() ?? '',
            'reason' => $action->getReason() ?? '',
            'detail' => $action->getReply() ?? '',
            'action' => $action->getAction(),
        ];

        // "Regen bei 70" rather than "Regen": what was asked for is the
        // interesting half of an event that carries parameters.
        foreach ($action->getInputs() ?? [] as $name => $value) {
            $tokens['input.'.$name] = is_scalar($value) ? $value : null;
        }

        return $tokens;
    }

    /** An event about the panel or the server rather than about a person. */
    public static function ofServer(
        string $type,
        GameServer $server,
        /** @var array<string, scalar|null> */
        array $tokens = [],
    ): self {
        return new self(
            $type,
            $server->getId()->toRfc4122(),
            $server->getName(),
            null,
            null,
            ['server' => $server->getName()] + $tokens,
            new \DateTimeImmutable(),
        );
    }
}
