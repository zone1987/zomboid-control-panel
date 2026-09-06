<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Bridge;

use App\Server\Bridge\BridgeCommandFailed;
use App\Server\Bridge\UtilityReading;
use PHPUnit\Framework\TestCase;

/**
 * "On" cannot say until when, which is the whole reason this carries the
 * shut-off day as well as the state.
 */
final class UtilityReadingTest extends TestCase
{
    public function testReadsBothUtilitiesAndTheDay(): void
    {
        $reading = self::of(120.5, ['on' => true, 'shutAt' => 130], ['on' => false, 'shutAt' => 110]);

        self::assertSame(120.5, $reading->day);
        self::assertTrue($reading->powerOn);
        self::assertSame(130, $reading->powerShutAt);
        self::assertFalse($reading->waterOn);
        self::assertSame(110, $reading->waterShutAt);
    }

    /** A blackout ten days out is worth saying, and worth rounding up. */
    public function testCountsTheDaysLeftUntilAShutOff(): void
    {
        $reading = self::of(120.5, ['on' => true, 'shutAt' => 130], ['on' => true, 'shutAt' => 121]);

        self::assertSame(10, $reading->daysLeft(true));
        // 120.5 to 121 is half a day, which is still a day to plan for.
        self::assertSame(1, $reading->daysLeft(false));
    }

    /**
     * A utility that never stops is not a countdown, and neither is one
     * already off — "−4 days remaining" would be worse than nothing.
     */
    public function testWhatIsNotACountdownHasNoDaysLeft(): void
    {
        $never = self::of(120.0, ['on' => true, 'shutAt' => UtilityReading::NEVER], ['on' => false, 'shutAt' => 30]);

        self::assertNull($never->daysLeft(true));
        self::assertNull($never->daysLeft(false));
    }

    /** A day already past cannot leave a negative remainder. */
    public function testAPastShutOffDayNeverCountsBackwards(): void
    {
        $reading = self::of(200.0, ['on' => true, 'shutAt' => 150], ['on' => true, 'shutAt' => 150]);

        self::assertSame(0, $reading->daysLeft(true));
    }

    public function testAnAnswerWithoutTheUtilitiesIsRefused(): void
    {
        $this->expectException(BridgeCommandFailed::class);

        UtilityReading::fromBridge(['day' => 10.0]);
    }

    /** The shape the endpoint hands to the interface. */
    public function testTheArrayCarriesTheCountdownItComputed(): void
    {
        $array = self::of(120.0, ['on' => true, 'shutAt' => 130], ['on' => false, 'shutAt' => 110])
            ->toArray();

        self::assertSame(10, $array['power']['daysLeft']);
        self::assertTrue($array['power']['on']);
        self::assertNull($array['water']['daysLeft']);
        self::assertFalse($array['water']['on']);
    }

    /**
     * @param array<string, mixed> $power
     * @param array<string, mixed> $water
     */
    private static function of(float $day, array $power, array $water): UtilityReading
    {
        return UtilityReading::fromBridge(['day' => $day, 'power' => $power, 'water' => $water]);
    }
}
