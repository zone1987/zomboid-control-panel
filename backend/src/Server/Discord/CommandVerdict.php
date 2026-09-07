<?php

declare(strict_types=1);

namespace App\Server\Discord;

/**
 * Why a command was or was not run.
 *
 * Distinct reasons rather than a boolean, because "you may not" and
 * "that command is not set up" send the operator to different places —
 * and somebody staring at a silent bot deserves to know which.
 */
enum CommandVerdict: string
{
    case Allowed = 'allowed';

    /** The server's Discord commands are switched off entirely. */
    case CommandsOff = 'commandsOff';

    /** This guild is not the one linked to this server. */
    case WrongGuild = 'wrongGuild';

    /** No capability mapping, so nothing gates it, so it is refused. */
    case UnknownCommand = 'unknownCommand';

    /** The member holds none of the roles allowed to run it. */
    case NotAllowed = 'notAllowed';

    public function isAllowed(): bool
    {
        return $this === self::Allowed;
    }

    /** What the member is told, as a translation key. */
    public function messageKey(): string
    {
        return 'discord.verdict.'.$this->value;
    }
}
