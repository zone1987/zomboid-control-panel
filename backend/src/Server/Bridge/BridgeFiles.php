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

    /** Everything before 0.4.0 lived in one file. */
    public const LEGACY = self::DIRECTORY.'/status.json';
}
