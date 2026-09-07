<?php

declare(strict_types=1);

namespace App\Server\Discord;

use App\Entity\DiscordNotification;

/**
 * What the notifier needs to know about one event's settings.
 *
 * Narrower than the repository on purpose: announcing depends on
 * whether a row exists and what it says, and on nothing else. It also
 * keeps the repository doubleable in a test without unsealing it.
 */
interface NotificationSettings
{
    /** Null when the operator never configured this event, which means off. */
    public function forEvent(string $serverId, string $eventType): ?DiscordNotification;
}
