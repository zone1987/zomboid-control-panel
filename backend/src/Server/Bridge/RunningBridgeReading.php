<?php

declare(strict_types=1);

namespace App\Server\Bridge;

use App\Entity\GameServer;

/**
 * What the *running* bridge last wrote about itself.
 *
 * Narrower than ServerInfoReader on purpose: a reload has to be judged
 * on the version and the session id alone, and nothing about weather or
 * game time should be able to influence that verdict. It also keeps the
 * reader doubleable in a test without unsealing it.
 */
interface RunningBridgeReading
{
    /**
     * @return array{version: string|null, sessionId: string|null}|null
     *     null when nothing could be read at all, which is its own state
     *     and never to be treated as "no bridge"
     */
    public function runningBridge(GameServer $server): ?array;
}
