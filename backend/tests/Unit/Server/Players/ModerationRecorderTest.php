<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Players;

use App\Entity\GameServer;
use App\Entity\ModerationAction;
use App\Server\Players\ModerationRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * One place an administrative action is written down.
 *
 * The seam matters more than the saving: a notification feed will later
 * want to see every action go past, and nine literals in eight files is
 * nine places to remember.
 */
final class ModerationRecorderTest extends TestCase
{
    public function testRecordingOneActionPersistsAndFlushesIt(): void
    {
        $calls = [];
        $recorder = new ModerationRecorder(self::entityManager($calls));

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
        $recorder = new ModerationRecorder(self::entityManager($calls));

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
