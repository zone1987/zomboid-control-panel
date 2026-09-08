<?php

declare(strict_types=1);

namespace App\Tests\Unit\Panel;

use App\Entity\AppSetting;
use App\Panel\DeployTrigger;
use App\Repository\AppSettingRepository;
use App\Settings\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * How often the panel looks for a new release.
 *
 * The scheduler fires at the shortest interval on offer, so the choice
 * has to be honoured here -- otherwise changing it in the interface
 * would need a restart to take effect.
 */
final class DeployIntervalTest extends TestCase
{
    public function testAnInstallationThatNeverChoseGetsAnHour(): void
    {
        self::assertSame(60, $this->trigger(null)->checkIntervalMinutes());
    }

    public function testHonoursEachIntervalTheInterfaceOffers(): void
    {
        foreach (DeployTrigger::CHECK_INTERVALS as $minutes) {
            self::assertSame(
                $minutes,
                $this->trigger((string) $minutes)->checkIntervalMinutes(),
                $minutes.' is offered but not honoured',
            );
        }
    }

    /**
     * Five minutes is the floor on purpose: at one minute the panel
     * would make sixty GitHub calls an hour, exactly the
     * unauthenticated limit, and a rate-limited check cannot answer.
     */
    public function testTheShortestIntervalIsFiveMinutes(): void
    {
        self::assertSame(5, min(DeployTrigger::CHECK_INTERVALS));
    }

    /** A day is the longest on offer; beyond that nobody is watching. */
    public function testTheLongestIntervalIsADay(): void
    {
        self::assertSame(1440, max(DeployTrigger::CHECK_INTERVALS));
    }

    /** Every choice is a whole number of minutes and strictly rising. */
    public function testTheChoicesAreOrderedAndDistinct(): void
    {
        $intervals = DeployTrigger::CHECK_INTERVALS;

        self::assertSame($intervals, array_values(array_unique($intervals)));

        $sorted = $intervals;
        sort($sorted);

        self::assertSame($sorted, $intervals, 'the dropdown would read out of order');
    }

    /** The scheduler fires at the floor, so nothing longer is missed. */
    public function testTheSchedulerRunsAtTheShortestInterval(): void
    {
        $schedule = file_get_contents(__DIR__.'/../../../src/Scheduler/MainSchedule.php');

        self::assertIsString($schedule);
        self::assertStringContainsString(
            "RecurringMessage::every('5 minutes', new DeployNewRelease())",
            $schedule,
            'the scheduler must fire at the shortest interval the interface offers',
        );
    }

    /**
     * An unrecognised value is not quietly turned into something else
     * that happens to be valid -- it falls back to the stated default.
     */
    public function testRefusesAnIntervalNobodyOffered(): void
    {
        foreach (['7', '0', '-5', 'soon', '99999'] as $stored) {
            self::assertSame(
                DeployTrigger::DEFAULT_CHECK_MINUTES,
                $this->trigger($stored)->checkIntervalMinutes(),
                $stored.' should not be accepted as an interval',
            );
        }
    }

    private function trigger(?string $minutes): DeployTrigger
    {
        $stored = $minutes === null
            ? []
            : [new AppSetting(AppSetting::DEPLOY_CHECK_MINUTES, $minutes)];

        return new DeployTrigger(
            new MockHttpClient([]),
            new SettingsProvider(
                new IntervalSettingRepository($stored),
                $this->createStub(EntityManagerInterface::class),
                [],
            ),
            new NullLogger(),
        );
    }
}

final class IntervalSettingRepository extends AppSettingRepository
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
