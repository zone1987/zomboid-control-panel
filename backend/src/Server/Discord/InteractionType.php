<?php

declare(strict_types=1);

namespace App\Server\Discord;

/**
 * Discord's interaction types, and the replies they expect.
 *
 * From its documented enum. `Ping` is the one that cannot be skipped:
 * Discord sends it when the endpoint URL is saved and refuses to accept
 * the URL unless it is answered with `Pong`.
 */
final class InteractionType
{
    public const PING = 1;
    public const APPLICATION_COMMAND = 2;
    public const AUTOCOMPLETE = 4;

    /** Reply types. */
    public const PONG = 1;

    /** A message answered immediately, within Discord's three seconds. */
    public const MESSAGE = 4;

    /**
     * "Working on it" — the only honest answer when the work involves
     * RCON or FTP, neither of which is reliably under three seconds.
     */
    public const DEFERRED_MESSAGE = 5;

    public const AUTOCOMPLETE_RESULT = 8;

    /** Only the person who ran the command sees the reply. */
    public const EPHEMERAL = 64;
}
