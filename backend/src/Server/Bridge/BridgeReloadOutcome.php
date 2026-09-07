<?php

declare(strict_types=1);

namespace App\Server\Bridge;

/**
 * What happened when the panel tried to load a fresh bridge without a
 * server restart.
 *
 * Four states, not two, and they must never collapse into one another.
 * Only `Active` means the new handler set is answering; everything else
 * — including every kind of not-knowing — means the operator still has
 * to restart. Reporting "applied" on an unverified reload is the same
 * mistake that cost an evening on the snow setting: write, read back
 * your own write, declare success, while the game held something else.
 */
enum BridgeReloadOutcome: string
{
    /** Reloaded, and the running bridge reports the version we shipped. */
    case Active = 'active';

    /** The game did not recognise the file: `Unknown Lua file`. */
    case NotReloaded = 'notReloaded';

    /** Reloaded, but the version read back is not the one uploaded. */
    case WrongVersion = 'wrongVersion';

    /** Reloaded, and then the bridge stopped answering. */
    case NotAnswering = 'notAnswering';

    /** RCON was unreachable, timed out, or answered something else. */
    case Unknown = 'unknown';

    /** No RCON credentials, so there is nothing to reload through. */
    case NoRcon = 'noRcon';

    /**
     * Whether the operator still has to restart the server.
     *
     * Everything that is not a verified success answers true — in doubt,
     * be wrong in the safe direction.
     */
    public function needsRestart(): bool
    {
        return $this !== self::Active;
    }

    /** The translation key explaining this outcome to the operator. */
    public function messageKey(): string
    {
        return 'bridge.reload.'.$this->value;
    }
}
