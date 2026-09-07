<?php

declare(strict_types=1);

namespace App\Server\Discord;

use App\Entity\GameServer;
use App\Security\Permission\Permission;

/**
 * Whether this Discord member may run this command on this server.
 *
 * **Two halves, both required.**
 *
 * The guild's half is `DiscordCommandRight`: which roles the operator
 * allowed. An absent row means nobody, never everybody — otherwise
 * adding a command to the catalogue would silently hand it to the whole
 * guild.
 *
 * The panel's half is `CommandCapabilities`: the permission the same act
 * costs in the interface. **Doing something through Discord must not be
 * cheaper than doing it in the panel**, so a command the panel does not
 * gate at all is refused rather than allowed — a subcommand added
 * without a mapping is a mistake, and treating it as public would be
 * the worst possible reading of it.
 */
final readonly class CommandAuthorisation
{
    public function __construct(private CommandRights $rights)
    {
    }

    public function decide(GameServer $server, Interaction $interaction): CommandVerdict
    {
        $config = $server->getDiscordConfig();

        if ($config === null || !$config->areCommandsEnabled()) {
            return CommandVerdict::CommandsOff;
        }

        // A member of one guild must not command another's server.
        if ($config->getGuildId() !== $interaction->guildId) {
            return CommandVerdict::WrongGuild;
        }

        // Unmapped means ungated, and ungated means refused.
        if (CommandCapabilities::of($interaction->command, $interaction->subcommand) === null) {
            return CommandVerdict::UnknownCommand;
        }

        $right = $this->rights->forCommand($server->getId()->toRfc4122(), $interaction->name());

        if ($right === null || !$right->allows($interaction->roleIds)) {
            return CommandVerdict::NotAllowed;
        }

        return CommandVerdict::Allowed;
    }

    /** What this command would cost in the panel, for the audit trail. */
    public function permissionFor(Interaction $interaction): ?Permission
    {
        return CommandCapabilities::of($interaction->command, $interaction->subcommand);
    }
}
