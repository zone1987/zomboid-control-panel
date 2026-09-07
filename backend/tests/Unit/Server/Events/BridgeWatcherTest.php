<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Events;

use App\Entity\GameServer;
use App\Server\Events\BridgeWatcher;
use App\Server\Events\PanelEventDispatcher;
use App\Server\Events\BridgeLiveness;
use App\Server\Players\BridgeUnavailable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Noticing that the bridge stopped, without saying so every minute.
 *
 * The transition is the event. Announcing the *state* on every poll
 * teaches the operator to ignore the channel, which is worse than
 * saying nothing at all — the reference panel's alert fatigue, learnt
 * from a status that flipped on every reconnect.
 */
final class BridgeWatcherTest extends TestCase
{
    /** The first observation is remembered, not announced. */
    public function testTheFirstReadingIsNotNews(): void
    {
        $sent = [];

        $this->watcher($sent, [false])->check($this->server());

        self::assertSame([], $sent, 'the panel starting up is not news about the server');
    }

    public function testAnnouncesWhenTheBridgeGoesQuiet(): void
    {
        $sent = [];
        $server = $this->server();
        $watcher = $this->watcher($sent, [false, true]);

        $watcher->check($server);
        $watcher->check($server);

        self::assertSame(['bridge.quiet'], $sent);
    }

    public function testAnnouncesWhenItComesBack(): void
    {
        $sent = [];
        $server = $this->server();
        $watcher = $this->watcher($sent, [true, false]);

        $watcher->check($server);
        $watcher->check($server);

        self::assertSame(['bridge.back'], $sent);
    }

    /** The one that stops alert fatigue. */
    public function testSaysNothingWhileTheStateHoldsSteady(): void
    {
        $sent = [];
        $server = $this->server();
        $watcher = $this->watcher($sent, [true, true, true, true]);

        for ($i = 0; $i < 4; ++$i) {
            $watcher->check($server);
        }

        self::assertSame([], $sent, 'a state that has not changed is not an event');
    }

    public function testAnnouncesEachChangeOnceAsItFlips(): void
    {
        $sent = [];
        $server = $this->server();
        $watcher = $this->watcher($sent, [false, true, true, false, false]);

        for ($i = 0; $i < 5; ++$i) {
            $watcher->check($server);
        }

        self::assertSame(['bridge.quiet', 'bridge.back'], $sent);
    }

    /**
     * A bridge that cannot be read at all is down: from here, unreadable
     * and unreachable are the same thing — the panel is not being told
     * what is happening.
     */
    public function testAnUnreadableBridgeCountsAsDown(): void
    {
        $sent = [];
        $server = $this->server();
        $watcher = $this->watcher($sent, [false, 'throw']);

        $watcher->check($server);
        $watcher->check($server);

        self::assertSame(['bridge.quiet'], $sent);
    }

    private function server(): GameServer
    {
        return new GameServer('Test');
    }

    /**
     * @param list<string>          $sent     collects the event types
     * @param list<bool|string>     $readings true means stale, 'throw' means unreadable
     */
    private function watcher(array &$sent, array $readings): BridgeWatcher
    {
        $reader = new class($readings) implements BridgeLiveness {
            /** @param list<bool|string> $readings */
            public function __construct(private array $readings)
            {
            }

            public function isStale(GameServer $server): bool
            {
                $next = array_shift($this->readings) ?? false;

                if ($next === 'throw') {
                    throw new BridgeUnavailable('bridge.fileUnreadable');
                }

                return (bool) $next;
            }
        };

        $bus = new class($sent) implements MessageBusInterface {
            /** @param list<string> $sent */
            public function __construct(private array &$sent)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($message instanceof \App\Server\Events\DeliverPanelEvent) {
                    $this->sent[] = $message->event->type;
                }

                return new Envelope($message);
            }
        };

        return new BridgeWatcher(
            $reader,
            new PanelEventDispatcher($bus, new NullLogger()),
            new ArrayAdapter(),
        );
    }
}
