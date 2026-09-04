<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Players;

use App\Entity\GameServer;
use App\Entity\RconConfig;
use App\Server\Players\PlayerModerator;
use App\Server\Rcon\RconClientInterface;
use App\Server\Rcon\RconCommandFailed;
use PHPUnit\Framework\TestCase;

final class PlayerModeratorTest extends TestCase
{
    public function testMovesAPlayerToAnotherPlayer(): void
    {
        $rcon = new RecordingRcon();

        $this->moderator($rcon)->teleportToPlayer($this->server(), 'bob', 'alice');

        self::assertSame(['teleportplayer "bob" "alice"'], $rcon->sent);
    }

    /**
     * The single-argument form of teleportto moves the caller, which over
     * RCON is nobody. Naming the player is what makes it work.
     */
    public function testNamesThePlayerWhenTeleportingToCoordinates(): void
    {
        $rcon = new RecordingRcon();

        $this->moderator($rcon)->teleportToCoordinates($this->server(), 'bob', 10778, 9770, 0);

        self::assertSame(['teleportto "bob" 10778,9770,0'], $rcon->sent);
    }

    public function testRefusesCoordinatesOutsideTheWorld(): void
    {
        $rcon = new RecordingRcon();

        $this->expectException(RconCommandFailed::class);

        $this->moderator($rcon)->teleportToCoordinates($this->server(), 'bob', -50, 9770, 0);
    }

    public function testRefusesAFloorTheGameDoesNotHave(): void
    {
        $this->expectException(RconCommandFailed::class);

        $this->moderator(new RecordingRcon())->teleportToCoordinates($this->server(), 'bob', 10778, 9770, 12);
    }

    public function testAcceptsABasementAndTheTopFloor(): void
    {
        self::assertTrue(PlayerModerator::isInsideWorld(10778, 9770, -1));
        self::assertTrue(PlayerModerator::isInsideWorld(10778, 9770, 7));
        self::assertFalse(PlayerModerator::isInsideWorld(10778, 9770, -2));
    }

    public function testStripsAQuoteFromTheUsernameBeforeTeleporting(): void
    {
        $rcon = new RecordingRcon();

        $this->moderator($rcon)->teleportToCoordinates($this->server(), 'bo"b', 100, 100, 0);

        self::assertSame(['teleportto "bob" 100,100,0'], $rcon->sent);
    }

    public function testRefusesAnUnknownAccessLevel(): void
    {
        $this->expectException(RconCommandFailed::class);

        $this->moderator(new RecordingRcon())->setAccessLevel($this->server(), 'bob', 'wizard');
    }

    public function testKicksWithAndWithoutAReason(): void
    {
        $rcon = new RecordingRcon();
        $moderator = $this->moderator($rcon);

        $moderator->kick($this->server(), 'bob');
        $moderator->kick($this->server(), 'bob', 'spawn camping');

        self::assertSame([
            'kick "bob"',
            'kick "bob" -r "spawn camping"',
        ], $rcon->sent);
    }

    public function testBansWithTheIpFlagBeforeTheReason(): void
    {
        $rcon = new RecordingRcon();

        $this->moderator($rcon)->ban($this->server(), 'bob', 'cheating', true);

        self::assertSame(['banuser "bob" -ip -r "cheating"'], $rcon->sent);
    }

    public function testRefusesToRunWithoutRconCredentials(): void
    {
        $this->expectException(RconCommandFailed::class);

        $this->moderator(new RecordingRcon())->kick(new GameServer('No RCON'), 'bob');
    }

    private function moderator(RecordingRcon $rcon): PlayerModerator
    {
        return new PlayerModerator($rcon);
    }

    private function server(): GameServer
    {
        $server = new GameServer('Test');
        new RconConfig($server, '127.0.0.1', 'secret');

        return $server;
    }
}

final class RecordingRcon implements RconClientInterface
{
    /** @var list<string> */
    public array $sent = [];

    public string $reply = 'ok';

    public function execute(RconConfig $config, string $command): string
    {
        $this->sent[] = $command;

        return $this->reply;
    }

    public function probe(RconConfig $config): string
    {
        return 'Players connected (0):';
    }
}
