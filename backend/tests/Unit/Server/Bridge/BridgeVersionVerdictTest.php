<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Bridge;

use App\Server\Bridge\BridgeVersionVerdict;
use PHPUnit\Framework\TestCase;

/**
 * A light reading the file rather than the game is a light that lies at
 * the one moment it matters: after an upload and before a restart.
 */
final class BridgeVersionVerdictTest extends TestCase
{
    public function testEverythingAgreeingIsUp(): void
    {
        $verdict = BridgeVersionVerdict::of('0.17.0', '0.17.0', '0.17.0');

        self::assertSame(BridgeVersionVerdict::UP, $verdict->state);
        self::assertNull($verdict->detail);
        self::assertSame('0.17.0', $verdict->version);
    }

    /**
     * The upload is done and the restart is not, which only the operator
     * can finish — so it has to be said rather than shown as fine.
     */
    public function testAnUploadWithoutARestartAsksForOne(): void
    {
        $verdict = BridgeVersionVerdict::of('0.15.0', '0.17.0', '0.17.0');

        self::assertSame(BridgeVersionVerdict::STALE, $verdict->state);
        self::assertSame('bridge.restartNeeded', $verdict->detail);
        // The running one, because that is what answers commands.
        self::assertSame('0.15.0', $verdict->version);
    }

    public function testAnOlderBridgeEverywhereIsOutdated(): void
    {
        $verdict = BridgeVersionVerdict::of('0.15.0', '0.15.0', '0.17.0');

        self::assertSame(BridgeVersionVerdict::STALE, $verdict->state);
        self::assertSame('bridge.outdated', $verdict->detail);
    }

    /** Before the bridge has written anything, the file is all there is. */
    public function testTheFileAnswersWhenNothingIsRunning(): void
    {
        $verdict = BridgeVersionVerdict::of(null, '0.17.0', '0.17.0');

        self::assertSame(BridgeVersionVerdict::UP, $verdict->state);
        self::assertSame('0.17.0', $verdict->version);
    }

    public function testNeitherSideKnowingIsNotUp(): void
    {
        $verdict = BridgeVersionVerdict::of(null, null, '0.17.0');

        self::assertSame(BridgeVersionVerdict::STALE, $verdict->state);
        self::assertSame('bridge.noReading', $verdict->detail);
    }
}
