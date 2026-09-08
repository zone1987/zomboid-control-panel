<?php

declare(strict_types=1);

namespace App\Security\Permission;

/**
 * One capability the panel offers.
 *
 * These are what a role grants. The three legacy role names still work
 * and each maps onto a set of these, so an installation that predates
 * this keeps running while checks move over one at a time.
 */
enum Permission: string
{
    case ViewPlayers = 'players.view';
    case KickPlayers = 'players.kick';
    case BanPlayers = 'players.ban';
    case TeleportPlayers = 'players.teleport';
    case SetAccessLevel = 'players.accessLevel';
    case GiveItems = 'items.give';
    case ViewLog = 'log.view';
    case ReadChat = 'chat.read';
    case SendChat = 'chat.send';
    case UseConsole = 'console.use';
    case TriggerEvents = 'events.trigger';
    case ViewVehicles = 'vehicles.view';
    case ViewServers = 'servers.view';
    case EditServers = 'servers.edit';
    case ManageBridge = 'servers.bridge';
    case EditServerConfig = 'servers.config';
    case InviteUsers = 'users.invite';
    case ManageUsers = 'users.manage';
    case EditSettings = 'settings.edit';

    /**
     * Reading a stored secret back in the clear.
     *
     * Separate from editing on purpose: entering a token and being
     * able to read every existing one are different powers, and a
     * deploy hook is as good as a login. Without this the reveal
     * control is not rendered at all -- an eye that refuses is worse
     * than no eye.
     */
    case RevealSecrets = 'settings.reveal';
    case ManageDiscord = 'discord.manage';

    /**
     * Grouped for the interface, in the order they are shown.
     *
     * @return array<string, list<self>>
     */
    public static function grouped(): array
    {
        return [
            'players' => [
                self::ViewPlayers,
                self::KickPlayers,
                self::BanPlayers,
                self::TeleportPlayers,
                self::SetAccessLevel,
                self::GiveItems,
            ],
            'world' => [
                self::TriggerEvents,
                self::UseConsole,
                self::ViewVehicles,
            ],
            'communication' => [
                self::ReadChat,
                self::SendChat,
                self::ViewLog,
                self::ManageDiscord,
            ],
            'servers' => [
                self::ViewServers,
                self::EditServers,
                self::ManageBridge,
                self::EditServerConfig,
            ],
            'administration' => [
                self::InviteUsers,
                self::ManageUsers,
                self::EditSettings,
                self::RevealSecrets,
            ],
        ];
    }

    /**
     * Permissions that hand over more than they appear to.
     *
     * Editing a server means seeing its FTP and RCON credentials; the
     * console runs any command the account may run, including ones that
     * stop the server. The interface says so where they are ticked.
     */
    public function isSensitive(): bool
    {
        return match ($this) {
            self::EditServers, self::UseConsole, self::ManageUsers,
            self::EditSettings, self::RevealSecrets => true,
            default => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function tryFromName(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
