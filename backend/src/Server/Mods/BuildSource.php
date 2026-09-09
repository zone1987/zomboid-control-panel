<?php

declare(strict_types=1);

namespace App\Server\Mods;

use App\Entity\GameServer;
use App\Server\Bridge\GameVersionReading;

/**
 * Which build a server runs, and where that was learnt.
 *
 * Two sources, and the difference matters to the operator: the bridge
 * reports what the running game actually is, while a typed value is
 * what somebody believes. The reported one wins, and where it differs
 * from the typed one the interface can say so rather than silently
 * preferring one.
 *
 * The bridge only started reporting it in 0.22.0, so an older one — or
 * a server that has not run since the upload — still leaves the typed
 * value as the only answer.
 */
final readonly class BuildSource
{
    public function __construct(private GameVersionReading $info)
    {
    }

    public function resolve(GameServer $server): BuildReading
    {
        $entered = $server->getGameBuild();
        $reported = null;
        $fullVersion = null;

        $game = $this->info->gameVersion($server);

        if ($game !== null) {
            $major = $game['major'] ?? null;

            if (\is_int($major) || (\is_string($major) && $major !== '')) {
                $reported = (string) $major;
            }

            // The whole version, for showing rather than filtering: the
            // workshop tags by build alone, but "42.20.1" is what an
            // operator recognises.
            foreach (['full', 'version'] as $key) {
                if (\is_string($game[$key] ?? null) && $game[$key] !== '') {
                    $fullVersion = $game[$key];

                    break;
                }
            }
        }

        return new BuildReading(
            build: GameBuild::of($reported ?? $entered),
            reported: $reported,
            entered: $entered,
            fullVersion: $fullVersion,
        );
    }
}
