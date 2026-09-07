<?php

declare(strict_types=1);

namespace App\Server\Config;

/**
 * Whether a saved change is live, or the server still has to restart.
 *
 * Measured on a live server rather than assumed, and the two files
 * differ:
 *
 * **server.ini — applied.** `reloadoptions` runs
 * `ServerOptions.init()`, which re-reads the INI, and
 * `sendOptionsToClients()`, which pushes it to everyone connected.
 * Measured: MaxPlayers 32 in the game and 33 in the file became 33 in
 * the game after the command.
 *
 * **SandboxVars.lua — restart needed, and nothing can change that from
 * outside the game.** The game's own `SandboxVars.lua` assigns the table
 * *and* calls `getSandboxOptions():initSandboxVars()`, which is the step
 * that copies each value into the Java option the game actually reads. A
 * server's own file only assigns the table. So `reloadlua` refreshes the
 * table and the running options keep what they loaded at start —
 * measured: the file said 15, the game kept 14, and `reloadoptions` did
 * not help either, since its bytecode never touches SandboxOptions.
 *
 * `Applied` is therefore the only value that lets the operator skip the
 * restart, and every kind of not-knowing answers the other way.
 */
enum ConfigApplyOutcome: string
{
    /** Reloaded and confirmed against the running server. */
    case Applied = 'applied';

    /** Written; the game reads it at its next start. */
    case RestartNeeded = 'restartNeeded';

    /** Reloaded, but the running server does not report the new value. */
    case ReloadUnconfirmed = 'reloadUnconfirmed';

    /** RCON was unreachable or answered something unrecognised. */
    case ReloadUnknown = 'reloadUnknown';

    /** No RCON credentials, so there is nothing to reload through. */
    case NoRcon = 'noRcon';

    public function needsRestart(): bool
    {
        return $this !== self::Applied;
    }

    public function messageKey(): string
    {
        return 'config.apply.'.$this->value;
    }
}
