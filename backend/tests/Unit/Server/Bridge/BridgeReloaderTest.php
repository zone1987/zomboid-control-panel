<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Bridge;

use App\Entity\GameServer;
use App\Entity\RconConfig;
use App\Server\Bridge\BridgeReloadOutcome;
use App\Server\Bridge\BridgeReloader;
use App\Server\Bridge\RunningBridgeReading;
use App\Server\Rcon\RconClientInterface;
use App\Server\Rcon\RconUnreachable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Loading a new bridge without a restart, and refusing to claim it.
 *
 * The interesting cases are the failures: every one of them has to reach
 * the operator as "restart needed" rather than as success. A reload that
 * reports "active" while the old handler set is answering is worse than
 * one that reports nothing.
 */
final class BridgeReloaderTest extends TestCase
{
    public function testReportsActiveWhenTheRunningVersionMatches(): void
    {
        $outcome = $this->reload(
            reply: 'Lua file reloaded',
            readings: [
                ['version' => '0.20.0', 'sessionId' => 'old'],
                ['version' => '0.21.0', 'sessionId' => 'new'],
            ],
            expected: '0.21.0',
        );

        self::assertSame(BridgeReloadOutcome::Active, $outcome);
        self::assertFalse($outcome->needsRestart());
    }

    public function testReportsNotReloadedWhenTheGameCannotFindTheFile(): void
    {
        $outcome = $this->reload(
            reply: 'Unknown Lua file',
            readings: [['version' => '0.20.0', 'sessionId' => 'old']],
            expected: '0.21.0',
        );

        self::assertSame(BridgeReloadOutcome::NotReloaded, $outcome);
        self::assertTrue($outcome->needsRestart());
    }

    /**
     * Reloaded, fresh session, but the code answering is not the code we
     * uploaded. This is the case the reply alone cannot distinguish.
     */
    public function testReportsWrongVersionWhenTheReadBackDisagrees(): void
    {
        $outcome = $this->reload(
            reply: 'Lua file reloaded',
            readings: [
                ['version' => '0.20.0', 'sessionId' => 'old'],
                ['version' => '0.19.0', 'sessionId' => 'new'],
            ],
            expected: '0.21.0',
        );

        self::assertSame(BridgeReloadOutcome::WrongVersion, $outcome);
        self::assertTrue($outcome->needsRestart());
    }

    /**
     * The session id is what proves the module re-ran. A reading left
     * over from before the reload carries the same id, and taking it as
     * confirmation is exactly the read-back-your-own-write trap.
     */
    public function testAStaleReadingIsNotConfirmation(): void
    {
        $outcome = $this->reload(
            reply: 'Lua file reloaded',
            // Every reading is the pre-reload one, version included.
            readings: array_fill(0, 8, ['version' => '0.21.0', 'sessionId' => 'old']),
            expected: '0.21.0',
        );

        self::assertSame(BridgeReloadOutcome::NotAnswering, $outcome);
        self::assertTrue($outcome->needsRestart());
    }

    public function testReportsNotAnsweringWhenNothingIsWrittenAtAll(): void
    {
        $outcome = $this->reload(
            reply: 'Lua file reloaded',
            readings: [['version' => '0.20.0', 'sessionId' => 'old']],
            expected: '0.21.0',
        );

        self::assertSame(BridgeReloadOutcome::NotAnswering, $outcome);
    }

    public function testReportsUnknownWhenRconCannotBeReached(): void
    {
        $outcome = $this->reload(
            reply: null,
            readings: [['version' => '0.20.0', 'sessionId' => 'old']],
            expected: '0.21.0',
        );

        self::assertSame(BridgeReloadOutcome::Unknown, $outcome);
        self::assertTrue($outcome->needsRestart());
    }

    /** An answer we do not recognise is not a success. */
    public function testReportsUnknownForAnUnrecognisedReply(): void
    {
        $outcome = $this->reload(
            reply: 'Enter a valid command',
            readings: [['version' => '0.20.0', 'sessionId' => 'old']],
            expected: '0.21.0',
        );

        self::assertSame(BridgeReloadOutcome::Unknown, $outcome);
    }

    public function testReportsNoRconWhenThereAreNoCredentials(): void
    {
        $reloader = new BridgeReloader(
            $this->createStub(RconClientInterface::class),
            $this->createStub(RunningBridgeReading::class),
            new NullLogger(),
            0,
        );

        $outcome = $reloader->reload(new GameServer('Test'), '0.21.0');

        self::assertSame(BridgeReloadOutcome::NoRcon, $outcome);
        self::assertTrue($outcome->needsRestart());
    }

    /** Only one outcome may ever say the restart can be skipped. */
    public function testEveryOutcomeExceptActiveNeedsARestart(): void
    {
        foreach (BridgeReloadOutcome::cases() as $case) {
            self::assertSame(
                $case !== BridgeReloadOutcome::Active,
                $case->needsRestart(),
                sprintf('"%s" must not claim the restart can be skipped', $case->value),
            );
        }
    }

    public function testTheCommandNamesTheBridgeFileOnly(): void
    {
        $rcon = $this->createMock(RconClientInterface::class);
        $rcon->expects(self::once())
            ->method('execute')
            // endsWith matches inside LuaManager.loaded, so no directory
            // and no server name is needed -- and must not be guessed.
            ->with(self::anything(), 'reloadlua ZomboidControlBridge.lua')
            ->willReturn('Lua file reloaded');

        $info = $this->createStub(RunningBridgeReading::class);
        $info->method('runningBridge')->willReturn(['version' => '0.21.0', 'sessionId' => 'new']);

        (new BridgeReloader($rcon, $info, new NullLogger(), 0))->reload($this->server(), '0.21.0');
    }

    /**
     * @param list<array{version: string|null, sessionId: string|null}> $readings
     */
    private function reload(?string $reply, array $readings, string $expected): BridgeReloadOutcome
    {
        $rcon = $this->createStub(RconClientInterface::class);

        if ($reply === null) {
            $rcon->method('execute')->willThrowException(new RconUnreachable('unreachable'));
        } else {
            $rcon->method('execute')->willReturn($reply);
        }

        $sequence = $readings;

        $info = $this->createStub(RunningBridgeReading::class);
        // Once the scripted readings run out, the bridge is silent --
        // which is the same shape as a server that stopped writing.
        $info->method('runningBridge')->willReturnCallback(
            static function () use (&$sequence): ?array {
                return array_shift($sequence);
            },
        );

        return (new BridgeReloader($rcon, $info, new NullLogger(), 0))->reload($this->server(), $expected);
    }

    private function server(): GameServer
    {
        $server = new GameServer('Test');
        // The constructor wires itself onto the server.
        new RconConfig($server, '127.0.0.1', 'secret');

        return $server;
    }
}
