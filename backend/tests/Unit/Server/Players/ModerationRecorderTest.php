<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Players;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use App\Server\Events\PanelEventDispatcher;
use App\Server\Players\ModerationRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One place an administrative action is written down.
 *
 * The seam matters more than the saving: the notification feed and
 * Discord both watch every action go past, and nine literals in eight
 * files was nine places to remember.
 */
final class ModerationRecorderTest extends TestCase
{
    /**
     * Every recorded action reaches the event stream.
     *
     * This is the property the bell and Discord rest on: an action
     * written without an event is one nobody hears about, and it would
     * only show up as a silence.
     */
    public function testEveryRecordedActionIsCollectedForTheEventStream(): void
    {
        $calls = [];
        $events = self::events();
        $recorder = new ModerationRecorder(self::entityManager($calls), $events);

        $recorder->record(new GameServer('Test'), ModerationAction::KICK, 'bob', null);

        self::assertCount(1, $events->pending());
        self::assertSame('moderation.'.ModerationAction::KICK, $events->pending()[0]->type);
        self::assertSame('bob', $events->pending()[0]->subject);
    }

    /** The batch form too, or half the actions would be silent. */
    public function testQueueingAnActionAlsoCollectsAnEvent(): void
    {
        $calls = [];
        $events = self::events();
        $recorder = new ModerationRecorder(self::entityManager($calls), $events);

        $recorder->add(new GameServer('Test'), ModerationAction::JOIN, 'bob', null);

        self::assertCount(1, $events->pending());
    }

    /**
     * Collected, not sent: `add()` writes without flushing, so
     * announcing at that point could announce a rolled-back row.
     */
    public function testNothingIsSentBeforeTheFlush(): void
    {
        $calls = [];
        $sent = 0;
        $bus = self::bus($sent);
        $events = new PanelEventDispatcher($bus, new NullLogger());

        (new ModerationRecorder(self::entityManager($calls), $events))
            ->add(new GameServer('Test'), ModerationAction::JOIN, 'bob', null);

        self::assertSame(0, $sent, 'an event must wait for the commit');

        $events->release();

        self::assertSame(1, $sent);
        self::assertSame([], $events->pending(), 'released events are not sent twice');
    }

    private static function events(): PanelEventDispatcher
    {
        $ignored = 0;

        return new PanelEventDispatcher(self::bus($ignored), new NullLogger());
    }

    private static function bus(int &$sent): MessageBusInterface
    {
        return new class($sent) implements MessageBusInterface {
            public function __construct(private int &$sent)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                ++$this->sent;

                return new Envelope($message);
            }
        };
    }

    public function testRecordingOneActionPersistsAndFlushesIt(): void
    {
        $calls = [];
        $recorder = new ModerationRecorder(self::entityManager($calls), self::events());

        $entry = $recorder->record(
            new GameServer('Test'),
            ModerationAction::KICK,
            'bob',
            null,
            'rude',
            'Kicked',
        );

        self::assertSame(['persist', 'flush'], $calls);
        self::assertSame(ModerationAction::KICK, $entry->getAction());
        self::assertSame('bob', $entry->getUsername());
        self::assertSame('rude', $entry->getReason());
        self::assertSame('Kicked', $entry->getReply());
    }

    /**
     * The batch callers flush once at the end, so queuing must not flush
     * — turning their single transaction into one per row would be a
     * quiet regression nobody notices until a busy server slows down.
     */
    public function testQueuingOneActionDoesNotFlush(): void
    {
        $calls = [];
        $recorder = new ModerationRecorder(self::entityManager($calls), self::events());

        $recorder->add(new GameServer('Test'), ModerationAction::JOIN, 'bob', null);
        $recorder->add(new GameServer('Test'), ModerationAction::LEAVE, 'bob', null);

        self::assertSame(['persist', 'persist'], $calls);
    }

    /**
     * Every construction goes through here.
     *
     * Asserted against the sources rather than trusted: a new literal
     * elsewhere would work perfectly and silently bypass the one seam a
     * feed would hook into.
     */
    public function testNothingElseConstructsAModerationActionDirectly(): void
    {
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (self::sources() as $file) {
            $path = $file->getPathname();

            if (str_ends_with($path, 'ModerationRecorder.php')) {
                continue;
            }

            $contents = file_get_contents($path);

            if (\is_string($contents) && str_contains($contents, 'new ModerationAction(')) {
                $found[] = basename($path);
            }
        }

        self::assertSame(
            [],
            $found,
            'these build a ModerationAction directly instead of using the recorder',
        );
    }

    /** @return \Iterator<\SplFileInfo> */
    private static function sources(): \Iterator
    {
        $directory = new \RecursiveDirectoryIterator(
            __DIR__.'/../../../../src',
            \FilesystemIterator::SKIP_DOTS,
        );

        return new \CallbackFilterIterator(
            new \RecursiveIteratorIterator($directory),
            static fn (\SplFileInfo $file): bool => $file->getExtension() === 'php',
        );
    }

    /** @param list<string> $calls */
    private static function entityManager(array &$calls): EntityManagerInterface
    {
        $entityManager = self::createStub(EntityManagerInterface::class);

        $entityManager->method('persist')->willReturnCallback(
            static function () use (&$calls): void {
                $calls[] = 'persist';
            },
        );

        $entityManager->method('flush')->willReturnCallback(
            static function () use (&$calls): void {
                $calls[] = 'flush';
            },
        );

        return $entityManager;
    }
}
