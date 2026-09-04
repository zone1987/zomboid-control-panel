<?php

declare(strict_types=1);

namespace App\Server\Events;

/**
 * One thing the event console can do to the world.
 *
 * The catalogue is declared once here and handed to the interface, so a
 * control and the command behind it cannot drift apart.
 */
final readonly class EventAction
{
    public const GROUP_WEATHER = 'weather';
    public const GROUP_SOUNDS = 'sounds';
    public const GROUP_PLAYERS = 'players';
    public const GROUP_WORLD = 'world';

    public const CHANNEL_RCON = 'rcon';
    public const CHANNEL_BRIDGE = 'bridge';

    /**
     * @param list<EventField>   $fields
     * @param list<string>       $commands  RCON commands this needs; the
     *                                      action is offered only when the
     *                                      server reports all of them
     */
    public function __construct(
        public string $id,
        public string $group,
        public string $channel,
        public array $commands,
        public array $fields = [],
        public bool $destructive = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'group' => $this->group,
            'channel' => $this->channel,
            'commands' => $this->commands,
            'destructive' => $this->destructive,
            'fields' => array_map(static fn (EventField $f): array => $f->toArray(), $this->fields),
        ];
    }
}
