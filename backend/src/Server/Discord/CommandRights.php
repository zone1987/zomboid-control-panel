<?php

declare(strict_types=1);

namespace App\Server\Discord;

use App\Entity\DiscordCommandRight;

/**
 * Which Discord roles the operator allowed for one subcommand.
 *
 * Narrower than the repository on purpose: the decision rests on one
 * lookup, and nothing else about persistence should be able to reach
 * into it. It also keeps the repository doubleable without unsealing it.
 */
interface CommandRights
{
    /** Null when nobody ever configured it, which means nobody may run it. */
    public function forCommand(string $serverId, string $command): ?DiscordCommandRight;
}
