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
    /**
     * The five categories, which decide the page an action lives on.
     *
     * A backend concept because it also decides the route, the nav entry
     * and the breadcrumb; a frontend-only map would be a second table
     * that can drift from this one.
     */
    public const CATEGORY_WEATHER = 'weather';
    public const CATEGORY_SOUNDS = 'sounds';
    public const CATEGORY_ACTIONS = 'actions';
    public const CATEGORY_ZOMBIES = 'zombies';
    public const CATEGORY_WORLD = 'world';

    /** In the order the sidebar and the overview show them. */
    public const CATEGORIES = [
        self::CATEGORY_WEATHER,
        self::CATEGORY_SOUNDS,
        self::CATEGORY_ACTIONS,
        self::CATEGORY_ZOMBIES,
        self::CATEGORY_WORLD,
    ];

    public const CHANNEL_RCON = 'rcon';
    public const CHANNEL_BRIDGE = 'bridge';

    /**
     * Try the bridge, fall back to RCON.
     *
     * For rain, which both can do: the bridge's value is that it reads
     * back, RCON's is that it works with no bridge installed, and an
     * operator should not have to know which button to press.
     */
    public const CHANNEL_PREFERRED = 'preferred';

    /**
     * @param list<EventField>   $fields
     * @param list<string>       $commands  RCON commands this needs; the
     *                                      action is offered only when the
     *                                      server reports all of them
     */
    public function __construct(
        public string $id,
        public string $category,
        public string $channel,
        /** Empty for a bridge action: it needs no RCON command at all. */
        public array $commands = [],
        public array $fields = [],
        public bool $destructive = false,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'channel' => $this->channel,
            'commands' => $this->commands,
            'destructive' => $this->destructive,
            'fields' => array_map(static fn (EventField $f): array => $f->toArray(), $this->fields),
        ];
    }
}
