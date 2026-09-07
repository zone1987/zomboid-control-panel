<?php

declare(strict_types=1);

namespace App\Server\Discord;

use App\Security\Permission\Permission;

/**
 * Which panel permission each Discord subcommand costs.
 *
 * **Doing something through Discord must not be cheaper than doing it in
 * the panel.** So every subcommand names the permission its HTTP twin
 * requires, checked when rights are granted *and* again when the command
 * runs — a role edited after a command was registered must not leave a
 * standing licence.
 *
 * One table, and a test walks every subcommand to demand an entry:
 * a command added without one fails the suite rather than reaching
 * Discord ungated.
 */
final class CommandCapabilities
{
    /**
     * @var array<string, Permission> keyed `command.subcommand`
     */
    public const MAP = [
        // Players
        'spieler.kick' => Permission::KickPlayers,
        'spieler.bannen' => Permission::BanPlayers,
        'spieler.entbannen' => Permission::BanPlayers,
        'spieler.teleport' => Permission::TeleportPlayers,
        'spieler.zugriffsstufe' => Permission::SetAccessLevel,
        'spieler.item' => Permission::GiveItems,
        'spieler.liste' => Permission::ViewPlayers,
        'spieler.info' => Permission::ViewPlayers,

        // The world and the weather both go through the event catalogue,
        // which is one permission in the panel and stays one here.
        'welt.zeit' => Permission::TriggerEvents,
        'welt.strom' => Permission::TriggerEvents,
        'welt.wasser' => Permission::TriggerEvents,
        'wetter.regen' => Permission::TriggerEvents,
        'wetter.sturm' => Permission::TriggerEvents,
        'wetter.schnee' => Permission::TriggerEvents,
        'wetter.klar' => Permission::TriggerEvents,
        'wetter.nebel' => Permission::TriggerEvents,

        // Reading the server's state is what ViewServers is for.
        'server.status' => Permission::ViewServers,
        'server.spieler' => Permission::ViewPlayers,
        'server.nachricht' => Permission::SendChat,

        // `/rcon` must not be cheaper than the console page, which is
        // the most dangerous control the panel has.
        'server.konsole' => Permission::UseConsole,
        'server.speichern' => Permission::UseConsole,
    ];

    /**
     * The permission one subcommand costs.
     *
     * Null for a name nothing maps — and an unmapped command is refused
     * rather than allowed, which is the only safe default.
     */
    public static function of(string $command, string $subcommand): ?Permission
    {
        return self::MAP[$command.'.'.$subcommand] ?? null;
    }

    /** @return list<string> every `command.subcommand` that is gated */
    public static function names(): array
    {
        return array_keys(self::MAP);
    }
}
