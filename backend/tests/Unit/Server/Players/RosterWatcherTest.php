<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Players;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use App\Server\Events\PanelEventDispatcher;
use App\Server\Players\ModerationRecorder;
use App\Server\Players\RosterWatcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RosterWatcherTest extends TestCase
{
    public function testAPlayerAppearingForTheFirstTimeIsRecordedOnce(): void
    {
        $recorder = new RecordingEntityManager();

        $counts = $this->watcher($recorder)->record($this->server(), [], ['bob']);

        self::assertSame(['joined' => 1, 'left' => 0], $counts);
        self::assertSame([[ModerationAction::JOIN, 'bob']], $recorder->recorded());
    }

    public function testPollingAgainWithTheSameRosterRecordsNothing(): void
    {
        $recorder = new RecordingEntityManager();
        $watcher = $this->watcher($recorder);
        $server = $this->server();

        $watcher->record($server, [], ['bob']);
        $watcher->record($server, ['bob'], ['bob']);
        $watcher->record($server, ['bob'], ['bob']);

        self::assertSame([[ModerationAction::JOIN, 'bob']], $recorder->recorded());
    }

    public function testAPlayerDisappearingIsRecordedOnce(): void
    {
        $recorder = new RecordingEntityManager();
        $watcher = $this->watcher($recorder);
        $server = $this->server();

        $watcher->record($server, ['bob', 'alice'], ['alice']);
        $watcher->record($server, ['alice'], ['alice']);

        self::assertSame([[ModerationAction::LEAVE, 'bob']], $recorder->recorded());
    }

    public function testAnEmptyRosterIsNotEverybodyLeaving(): void
    {
        $recorder = new RecordingEntityManager();

        $counts = $this->watcher($recorder)->record(
            $this->server(),
            ['bob', 'alice', 'carol'],
            [],
            answered: false,
        );

        self::assertSame(['joined' => 0, 'left' => 0], $counts);
        self::assertSame([], $recorder->recorded());
        self::assertSame(0, $recorder->flushes);
    }

    public function testAnUnreadableBridgeRecordsNoLeavesEvenAcrossSeveralPolls(): void
    {
        $recorder = new RecordingEntityManager();
        $watcher = $this->watcher($recorder);
        $server = $this->server();

        foreach (range(1, 5) as $ignored) {
            $watcher->record($server, ['bob'], [], answered: false);
        }

        self::assertSame([], $recorder->recorded());
    }

    /** A server that really did empty must still close the timeline entry. */
    public function testAnAnsweringBridgeWithNobodyOnItRecordsTheDeparture(): void
    {
        $recorder = new RecordingEntityManager();

        $counts = $this->watcher($recorder)->record($this->server(), ['bob'], [], answered: true);

        self::assertSame(['joined' => 0, 'left' => 1], $counts);
        self::assertSame([[ModerationAction::LEAVE, 'bob']], $recorder->recorded());
    }

    public function testNobodyInThePanelIsCreditedWithAJoinOrALeave(): void
    {
        $recorder = new RecordingEntityManager();

        $this->watcher($recorder)->record($this->server(), ['bob'], ['alice']);

        self::assertCount(2, $recorder->persisted);

        foreach ($recorder->persisted as $action) {
            self::assertNull($action->getPerformedBy());
        }
    }

    public function testAJoinAndALeaveInTheSamePollAreBothRecorded(): void
    {
        $recorder = new RecordingEntityManager();

        $counts = $this->watcher($recorder)->record($this->server(), ['bob'], ['alice']);

        self::assertSame(['joined' => 1, 'left' => 1], $counts);
        self::assertEqualsCanonicalizing(
            [[ModerationAction::JOIN, 'alice'], [ModerationAction::LEAVE, 'bob']],
            $recorder->recorded(),
        );
    }

    public function testAQuietPollIsNotWrittenToTheDatabase(): void
    {
        $recorder = new RecordingEntityManager();

        $this->watcher($recorder)->record($this->server(), ['bob'], ['bob']);

        self::assertSame(0, $recorder->flushes);
    }

    public function testTheSameNameTwiceInOneRosterJoinsOnce(): void
    {
        $recorder = new RecordingEntityManager();

        $this->watcher($recorder)->record($this->server(), [], ['bob', 'bob', ' bob ']);

        self::assertSame([[ModerationAction::JOIN, 'bob']], $recorder->recorded());
    }

    private function watcher(RecordingEntityManager $recorder): RosterWatcher
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback($recorder->persist(...));
        $entityManager->method('flush')->willReturnCallback($recorder->flush(...));

        // The recorder shares the same entity manager, so everything it
        // queues still lands in the recording above.
        return new RosterWatcher(
            $entityManager,
            new ModerationRecorder($entityManager, new PanelEventDispatcher(
                new class implements MessageBusInterface {
                    public function dispatch(object $message, array $stamps = []): Envelope
                    {
                        return new Envelope($message);
                    }
                },
                new NullLogger(),
            )),
        );
    }

    private function server(): GameServer
    {
        return new GameServer('Test');
    }
}

/**
 * Collects what the watcher wanted written, so a test can read it back
 * without a database.
 */
final class RecordingEntityManager
{
    /** @var list<ModerationAction> */
    public array $persisted = [];

    public int $flushes = 0;

    public function persist(object $entity): void
    {
        \assert($entity instanceof ModerationAction);

        $this->persisted[] = $entity;
    }

    public function flush(): void
    {
        ++$this->flushes;
    }

    /** @return list<array{0: string, 1: string}> */
    public function recorded(): array
    {
        return array_map(
            static fn (ModerationAction $a): array => [$a->getAction(), $a->getUsername()],
            $this->persisted,
        );
    }
}
