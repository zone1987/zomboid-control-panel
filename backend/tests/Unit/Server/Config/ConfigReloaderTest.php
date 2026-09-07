<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Config;

use App\Entity\GameServer;
use App\Entity\RconConfig;
use App\Server\Config\ConfigApplyOutcome;
use App\Server\Config\ConfigKind;
use App\Server\Config\ConfigReloader;
use App\Server\Rcon\RconClientInterface;
use App\Server\Rcon\RconUnreachable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Whether a saved change is live, decided by asking the running server.
 *
 * Measured on a live server first: `reloadoptions` does make an INI
 * change take effect (MaxPlayers 32 → 33 in `showoptions`), and nothing
 * makes a sandbox change take effect, because a server's own
 * SandboxVars.lua never calls `initSandboxVars()`.
 */
final class ConfigReloaderTest extends TestCase
{
    /**
     * The sandbox cannot be reloaded at all, so nothing is even sent —
     * a `reloadlua` that answers "Lua file reloaded" while the value
     * stays put is worse than not trying.
     */
    public function testTheSandboxAlwaysNeedsARestartAndSendsNothing(): void
    {
        $rcon = $this->createMock(RconClientInterface::class);
        $rcon->expects(self::never())->method('execute');

        $outcome = (new ConfigReloader($rcon, new NullLogger()))
            ->apply($this->server(), ConfigKind::Sandbox, ['ElecShutModifier' => 15]);

        self::assertSame(ConfigApplyOutcome::RestartNeeded, $outcome);
        self::assertTrue($outcome->needsRestart());
    }

    public function testAnIniChangeIsAppliedWhenTheServerReportsIt(): void
    {
        $outcome = $this->apply(
            ['Options reloaded', "* MaxPlayers=33\n* PVP=true"],
            ['MaxPlayers' => 33],
        );

        self::assertSame(ConfigApplyOutcome::Applied, $outcome);
        self::assertFalse($outcome->needsRestart());
    }

    /** Reloaded, but the running server still holds the old value. */
    public function testAnIniChangeIsUnconfirmedWhenTheValueDidNotMove(): void
    {
        $outcome = $this->apply(
            ['Options reloaded', '* MaxPlayers=32'],
            ['MaxPlayers' => 33],
        );

        self::assertSame(ConfigApplyOutcome::ReloadUnconfirmed, $outcome);
        self::assertTrue($outcome->needsRestart());
    }

    /** A value the listing does not mention is not agreement. */
    public function testAValueTheServerDoesNotReportIsUnconfirmed(): void
    {
        $outcome = $this->apply(['Options reloaded', '* PVP=true'], ['MaxPlayers' => 33]);

        self::assertSame(ConfigApplyOutcome::ReloadUnconfirmed, $outcome);
    }

    public function testABooleanIsComparedAsTheServerPrintsIt(): void
    {
        self::assertSame(
            ConfigApplyOutcome::Applied,
            $this->apply(['Options reloaded', '* PVP=false'], ['PVP' => false]),
        );

        self::assertSame(
            ConfigApplyOutcome::ReloadUnconfirmed,
            $this->apply(['Options reloaded', '* PVP=true'], ['PVP' => false]),
        );
    }

    /** An answer that is not the success phrase is not a success. */
    public function testAnUnrecognisedReplyIsUnknown(): void
    {
        $outcome = $this->apply(['Enter a valid command', ''], ['MaxPlayers' => 33]);

        self::assertSame(ConfigApplyOutcome::ReloadUnknown, $outcome);
        self::assertTrue($outcome->needsRestart());
    }

    public function testAnUnreachableServerIsUnknown(): void
    {
        $rcon = $this->createStub(RconClientInterface::class);
        $rcon->method('execute')->willThrowException(new RconUnreachable('unreachable'));

        $outcome = (new ConfigReloader($rcon, new NullLogger()))
            ->apply($this->server(), ConfigKind::Ini, ['MaxPlayers' => 33]);

        self::assertSame(ConfigApplyOutcome::ReloadUnknown, $outcome);
    }

    public function testNoRconMeansNoReload(): void
    {
        $outcome = (new ConfigReloader($this->createStub(RconClientInterface::class), new NullLogger()))
            ->apply(new GameServer('Test'), ConfigKind::Ini, ['MaxPlayers' => 33]);

        self::assertSame(ConfigApplyOutcome::NoRcon, $outcome);
        self::assertTrue($outcome->needsRestart());
    }

    /** Only one outcome may let the operator skip the restart. */
    public function testEveryOutcomeExceptAppliedNeedsARestart(): void
    {
        foreach (ConfigApplyOutcome::cases() as $case) {
            self::assertSame(
                $case !== ConfigApplyOutcome::Applied,
                $case->needsRestart(),
                sprintf('"%s" must not claim the restart can be skipped', $case->value),
            );
        }
    }

    /**
     * @param list<string>                         $replies
     * @param array<string, bool|float|int|string> $written
     */
    private function apply(array $replies, array $written): ConfigApplyOutcome
    {
        $rcon = $this->createStub(RconClientInterface::class);
        $rcon->method('execute')->willReturnCallback(
            static function () use (&$replies): string {
                return array_shift($replies) ?? '';
            },
        );

        return (new ConfigReloader($rcon, new NullLogger()))
            ->apply($this->server(), ConfigKind::Ini, $written);
    }

    private function server(): GameServer
    {
        $server = new GameServer('Test');
        new RconConfig($server, '127.0.0.1', 'secret');

        return $server;
    }
}
