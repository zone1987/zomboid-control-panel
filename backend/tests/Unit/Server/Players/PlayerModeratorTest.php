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


    /**
     * Every ability takes -true/-false, so the panel sets a state rather
     * than toggling blind — and the command names are the server's own,
     * which spells this one without the second "e".
     */
    public function testSetsAnAbilityToAStateRatherThanToggling(): void
    {
        $rcon = new RecordingRcon();

        $this->moderator($rcon)->setAbility($this->server(), 'bob', 'god', true);
        $this->moderator($rcon)->setAbility($this->server(), 'bob', 'god', false);

        self::assertSame(
            ['godmodplayer "bob" -true', 'godmodplayer "bob" -false'],
            $rcon->sent,
        );
    }

    public function testKnowsEveryAbilityTheServerOffers(): void
    {
        $rcon = new RecordingRcon();

        foreach (array_keys(PlayerModerator::ABILITIES) as $ability) {
            $this->moderator($rcon)->setAbility($this->server(), 'bob', $ability, true);
        }

        self::assertSame(
            [
                'godmodplayer "bob" -true',
                'invisibleplayer "bob" -true',
                'noclip "bob" -true',
                'voiceban "bob" -true',
            ],
            $rcon->sent,
        );
    }

    public function testRefusesAnAbilityTheServerHasNoCommandFor(): void
    {
        $this->expectException(RconCommandFailed::class);

        $this->moderator(new RecordingRcon())->setAbility($this->server(), 'bob', 'fly', true);
    }

    public function testGrantsExperienceInOneSkill(): void
    {
        $rcon = new RecordingRcon();

        $this->moderator($rcon)->grantExperience($this->server(), 'bob', 'Woodwork', 200);

        self::assertSame(['addxp "bob" Woodwork=200'], $rcon->sent);
    }

    /** The multiplier is what "a level's worth" means on a server running one. */
    public function testAsksForTheServerMultiplierWhenTold(): void
    {
        $rcon = new RecordingRcon();

        $this->moderator($rcon)->grantExperience($this->server(), 'bob', 'Woodwork', 200, true);

        self::assertSame(['addxp "bob" Woodwork=200 -true'], $rcon->sent);
    }

    /**
     * The perk is unquoted in the command, so anything but letters could
     * end the argument and start another.
     */
    public function testRefusesASkillThatIsNotAPlainName(): void
    {
        foreach (['Woodwork=1 -true; quit', 'Wood work', 'Woodwork"', ''] as $perk) {
            try {
                $this->moderator(new RecordingRcon())->grantExperience($this->server(), 'bob', $perk, 1);

                self::fail(sprintf('"%s" was accepted as a skill', $perk));
            } catch (RconCommandFailed) {
                self::assertTrue(true);
            }
        }
    }

    public function testRefusesAnAmountOutsideTheCap(): void
    {
        foreach ([0, -5, PlayerModerator::MAX_XP + 1] as $amount) {
            try {
                $this->moderator(new RecordingRcon())
                    ->grantExperience($this->server(), 'bob', 'Woodwork', $amount);

                self::fail(sprintf('%d was accepted as an amount', $amount));
            } catch (RconCommandFailed) {
                self::assertTrue(true);
            }
        }
    }

    /** A name can be changed; the id is what makes a ban work. */
    public function testBansAndUnbansASteamId(): void
    {
        $rcon = new RecordingRcon();

        $this->moderator($rcon)->banSteamId($this->server(), '76561198000000000');
        $this->moderator($rcon)->unbanSteamId($this->server(), '76561198000000000');

        self::assertSame(
            ['banid 76561198000000000', 'unbanid 76561198000000000'],
            $rcon->sent,
        );
    }

    /** The id is unquoted too, so it is digits or nothing. */
    public function testRefusesAnythingThatIsNotASteamId(): void
    {
        foreach (['7656119800000000; quit', 'bob', '123', ''] as $id) {
            try {
                $this->moderator(new RecordingRcon())->banSteamId($this->server(), $id);

                self::fail(sprintf('"%s" was accepted as a SteamID', $id));
            } catch (RconCommandFailed) {
                self::assertTrue(true);
            }
        }
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
