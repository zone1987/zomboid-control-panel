<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Players;

use App\Entity\AppSetting;
use App\Entity\GameServer;
use App\Entity\PlayerSnapshot;
use App\Repository\AppSettingRepository;
use App\Repository\PlayerSnapshotRepository;
use App\Server\Players\StalePlayerPurger;
use App\Settings\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class StalePlayerPurgerTest extends TestCase
{
    private const NOW = '2026-09-06 12:00:00';

    public function testKeepsEverythingWhenRetentionIsOff(): void
    {
        $snapshots = $this->snapshotsSeenDaysAgo([400, 900]);

        $result = $this->purger($snapshots, null)->run();

        self::assertSame(0, $result['purged']);
        self::assertNull($result['cutoff']);
        self::assertCount(2, $snapshots->rows, 'Ancient rows must survive while retention is off.');
    }

    public function testKeepsEverythingWhenRetentionIsZero(): void
    {
        $snapshots = $this->snapshotsSeenDaysAgo([400]);

        $result = $this->purger($snapshots, '0')->run();

        self::assertSame(0, $result['purged']);
        self::assertNull($result['cutoff']);
        self::assertCount(1, $snapshots->rows);
    }

    public function testRemovesARowSeenLongerAgoThanTheHorizon(): void
    {
        $snapshots = $this->snapshotsSeenDaysAgo([31]);

        $result = $this->purger($snapshots, '30')->run();

        self::assertSame(1, $result['purged']);
        self::assertSame([], $snapshots->rows);
    }

    public function testKeepsARowSeenMoreRecentlyThanTheHorizon(): void
    {
        $snapshots = $this->snapshotsSeenDaysAgo([29]);

        $result = $this->purger($snapshots, '30')->run();

        self::assertSame(0, $result['purged']);
        self::assertCount(1, $snapshots->rows);
    }

    public function testRemovesOnlyTheRowsPastTheHorizon(): void
    {
        $snapshots = $this->snapshotsSeenDaysAgo([31, 29, 400, 1]);

        $result = $this->purger($snapshots, '30')->run();

        self::assertSame(2, $result['purged']);
        self::assertSame([29, 1], array_values(array_map(
            static fn (PlayerSnapshot $row): int => (int) $row->getLastSeenAt()
                ->diff(new \DateTimeImmutable(self::NOW))->days,
            $snapshots->rows,
        )));
    }

    /** The horizon is inclusive: N days of retention keeps a row seen exactly N days ago. */
    public function testKeepsARowExactlyOnTheHorizon(): void
    {
        $snapshots = $this->snapshotsSeenDaysAgo([30]);

        $result = $this->purger($snapshots, '30')->run();

        self::assertSame(0, $result['purged']);
        self::assertCount(1, $snapshots->rows, 'A row on the cutoff itself stays.');
    }

    public function testDryRunReportsTheCountWithoutDeletingAnything(): void
    {
        $snapshots = $this->snapshotsSeenDaysAgo([31, 400, 29]);

        $result = $this->purger($snapshots, '30')->run(dryRun: true);

        self::assertSame(2, $result['purged']);
        self::assertCount(3, $snapshots->rows, 'A dry run must delete nothing.');
        self::assertSame(0, $snapshots->deleteCalls);
    }

    public function testComputesTheCutoffFromTheHorizon(): void
    {
        $purger = $this->purger($this->snapshotsSeenDaysAgo([]), '30');

        self::assertSame(
            '2026-08-07 12:00:00',
            $purger->cutoff()?->format('Y-m-d H:i:s'),
        );
    }

    public function testTreatsANonNumericSettingAsOff(): void
    {
        $snapshots = $this->snapshotsSeenDaysAgo([400]);

        $result = $this->purger($snapshots, 'forever')->run();

        self::assertSame(0, $result['purged']);
        self::assertCount(1, $snapshots->rows);
    }

    public function testNeverAsksTheRepositoryToDeleteWhenRetentionIsOff(): void
    {
        $snapshots = $this->snapshotsSeenDaysAgo([400]);

        $this->purger($snapshots, null)->run();

        self::assertSame(0, $snapshots->deleteCalls);
    }

    private function purger(FakeSnapshotRepository $snapshots, ?string $retentionDays): StalePlayerPurger
    {
        $stored = $retentionDays === null
            ? []
            : [new AppSetting(AppSetting::PLAYER_RETENTION_DAYS, $retentionDays)];

        $settings = new SettingsProvider(
            new FakeAppSettingRepository($stored),
            $this->createStub(EntityManagerInterface::class),
            [],
        );

        return new StalePlayerPurger(
            $snapshots,
            $settings,
            new MockClock(new \DateTimeImmutable(self::NOW)),
            new NullLogger(),
        );
    }

    /** @param list<int> $daysAgo */
    private function snapshotsSeenDaysAgo(array $daysAgo): FakeSnapshotRepository
    {
        $now = new \DateTimeImmutable(self::NOW);
        $server = new GameServer('Test', 'host');
        $rows = [];

        foreach ($daysAgo as $index => $days) {
            $snapshot = new PlayerSnapshot($server, 'player'.$index);
            $snapshot->update(
                null,
                0.0,
                0.0,
                0.0,
                1.0,
                false,
                0.0,
                0.0,
                null,
                [],
                [],
                $now->sub(new \DateInterval('P'.$days.'D')),
            );

            $rows[] = $snapshot;
        }

        return new FakeSnapshotRepository($rows);
    }
}

final class FakeAppSettingRepository extends AppSettingRepository
{
    /** @param list<AppSetting> $stored */
    public function __construct(private readonly array $stored)
    {
    }

    /** @return array<string, string> */
    public function findAllAsMap(): array
    {
        $map = [];

        foreach ($this->stored as $setting) {
            $value = $setting->getValue();

            if ($value !== null && $value !== '') {
                $map[$setting->getName()] = $value;
            }
        }

        return $map;
    }
}

final class FakeSnapshotRepository extends PlayerSnapshotRepository
{
    public int $deleteCalls = 0;

    /** @param list<PlayerSnapshot> $rows */
    public function __construct(public array $rows)
    {
    }

    public function countLastSeenBefore(\DateTimeImmutable $cutoff): int
    {
        return \count($this->stale($cutoff));
    }

    public function deleteLastSeenBefore(\DateTimeImmutable $cutoff): int
    {
        ++$this->deleteCalls;

        $stale = $this->stale($cutoff);

        $this->rows = array_values(array_filter(
            $this->rows,
            static fn (PlayerSnapshot $row): bool => $row->getLastSeenAt() >= $cutoff,
        ));

        return \count($stale);
    }

    /** @return list<PlayerSnapshot> */
    private function stale(\DateTimeImmutable $cutoff): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (PlayerSnapshot $row): bool => $row->getLastSeenAt() < $cutoff,
        ));
    }
}
