<?php

declare(strict_types=1);

namespace App\Server\Bridge;

use App\Entity\GameServer;

/**
 * What the running game says its version is.
 *
 * A single method rather than the whole ServerInfoReader, which is
 * final and would make this untestable — and a collaborator that takes
 * one method cannot later reach for the weather (rule 10i).
 */
interface GameVersionReading
{
    /**
     * Null when the bridge has not written a version: either it is older
     * than 0.22.0, or the server has not run since it was uploaded.
     *
     * @return array<string, mixed>|null
     */
    public function gameVersion(GameServer $server): ?array;
}
