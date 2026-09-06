<?php

declare(strict_types=1);

namespace App\Server\Bridge;

/**
 * What the bridge writes, and where.
 *
 * Split into one file per subject from 0.4.0: the player roster changes
 * whenever somebody joins, while time, weather and safehouses move
 * slowly. Writing them together meant rewriting everything for every
 * join, and reading them together meant fetching it all to see who is
 * online.
 */
final class BridgeFiles
{
    /** Relative to the transfer base path. */
    public const DIRECTORY = 'Lua/ZomboidControl';

    public const PLAYERS = self::DIRECTORY.'/players.json';
    public const SERVER = self::DIRECTORY.'/server.json';
    public const SAFEHOUSES = self::DIRECTORY.'/safehouses.json';

    /** Written once per start; the catalogue only changes when mods do. */
    public const ITEMS = self::DIRECTORY.'/items.json';

    /** Where the loaded vehicles are, from bridge 0.10.0. */
    public const VEHICLES = self::DIRECTORY.'/vehicles.json';

    /** What can be spawned, as opposed to what is placed in the world. */
    public const VEHICLE_CATALOGUE = self::DIRECTORY.'/vehicle-catalogue.json';

    /** Factions and their members, from bridge 0.10.0. */
    public const FACTIONS = self::DIRECTORY.'/factions.json';

    /** Everything before 0.4.0 lived in one file. */
    public const LEGACY = self::DIRECTORY.'/status.json';

    /**
     * The command queue, from 0.8.0.
     *
     * Each side owns its files and only reads the other's: the panel
     * writes commands and its own cursor, the bridge writes results and
     * its own cursor.
     */
    public const COMMANDS = self::DIRECTORY.'/commands';
    public const RESULTS = self::DIRECTORY.'/results';
    public const BRIDGE_CURSOR = self::DIRECTORY.'/cursor.json';
    public const PANEL_CURSOR = self::DIRECTORY.'/panel-cursor.json';

    public static function commandFile(int $sequence): string
    {
        return sprintf('%s/cmd-%d.json', self::COMMANDS, $sequence);
    }

    public static function resultFile(int $sequence): string
    {
        return sprintf('%s/res-%d.json', self::RESULTS, $sequence);
    }
}
