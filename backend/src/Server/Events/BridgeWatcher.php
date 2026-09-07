<?php

declare(strict_types=1);

namespace App\Server\Events;

use App\Entity\GameServer;
use App\Server\Players\BridgeUnavailable;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Notices when the bridge stops answering, and when it comes back.
 *
 * **The transition is the event, not the state.** Announcing "the bridge
 * is quiet" on every poll would teach the operator to ignore the
 * channel, which is worse than saying nothing — the reference panel's
 * alert fatigue, learnt from a status that flipped on every two-second
 * reconnect.
 *
 * So the last known state is remembered and only a change is dispatched.
 * A first observation is remembered without announcing: the panel
 * starting up is not news about the server.
 */
final readonly class BridgeWatcher
{
    /** Three states, because "not read yet" is not "down". */
    private const UP = 'up';
    private const DOWN = 'down';

    public function __construct(
        private BridgeLiveness $reader,
        private PanelEventDispatcher $events,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return string the state now, for a caller that wants to know
     */
    public function check(GameServer $server): string
    {
        $now = $this->observe($server);
        $marker = $this->cache->getItem('events.bridge.'.$server->getId()->toRfc4122());
        $before = $marker->isHit() ? (string) $marker->get() : null;

        $marker->set($now);
        $this->cache->save($marker);

        // Nothing to compare against: remember and stay quiet.
        if ($before === null || $before === $now) {
            return $now;
        }

        $this->events->dispatch(PanelEvent::ofServer(
            $now === self::UP ? 'bridge.back' : 'bridge.quiet',
            $server,
        ));

        return $now;
    }

    private function observe(GameServer $server): string
    {
        try {
            return $this->reader->isStale($server) ? self::DOWN : self::UP;
        } catch (BridgeUnavailable) {
            // Unreadable and unreachable are the same thing from here:
            // the panel is not being told what is happening.
            return self::DOWN;
        }
    }
}
