<?php

declare(strict_types=1);

namespace App\Server\Events;

use App\Entity\GameServer;
use App\Server\Players\BridgeUnavailable;

/**
 * Whether a server's bridge is still writing.
 *
 * One question, which is all the watcher needs: player counts and
 * versions must not be able to sway a decision about liveness. Per
 * CLAUDE.md 10i, an interface rather than unsealing the reader.
 */
interface BridgeLiveness
{
    /**
     * True when the last reading is older than the staleness window.
     *
     * @throws BridgeUnavailable when there is nothing to read at all
     */
    public function isStale(GameServer $server): bool;
}
