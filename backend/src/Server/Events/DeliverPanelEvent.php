<?php

declare(strict_types=1);

namespace App\Server\Events;

/**
 * The queued instruction to announce one event.
 *
 * Carries the event itself rather than an id, because a `PanelEvent` is
 * not persisted — and because the announcement should describe what
 * happened at the time it happened, not what the row looks like when
 * the worker gets round to it.
 */
final readonly class DeliverPanelEvent
{
    public function __construct(public PanelEvent $event)
    {
    }
}
