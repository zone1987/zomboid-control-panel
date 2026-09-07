<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Discord;

use App\Entity\DiscordCommandRight;
use App\Entity\DiscordConfig;
use App\Entity\GameServer;
use App\Server\Discord\CommandAuthorisation;
use App\Server\Discord\CommandRights;
use App\Server\Discord\CommandVerdict;
use App\Server\Discord\Interaction;
use App\Server\Discord\InteractionType;
use PHPUnit\Framework\TestCase;

/**
 * Who may run what, and why not.
 *
 * The refusals are the interesting cases: an over-permissive default
 * here hands a guild the console, and would do it silently.
 */
final class CommandAuthorisationTest extends TestCase
{
    private const GUILD = '111111111111111111';
    private const MODERATOR_ROLE = '222222222222222222';

    public function testAllowsAMemberHoldingAnAllowedRole(): void
    {
        $server = $this->server();
        $right = new DiscordCommandRight($server, 'spieler.kick');
        $right->setRoleIds([self::MODERATOR_ROLE]);

        self::assertSame(
            CommandVerdict::Allowed,
            $this->decide($server, $right, roles: [self::MODERATOR_ROLE]),
        );
    }

    /**
     * The one that matters most: a command nobody configured must not
     * be open, or adding one to the catalogue would hand it to the
     * whole guild.
     */
    public function testRefusesACommandWhoseRightsWereNeverSet(): void
    {
        self::assertSame(
            CommandVerdict::NotAllowed,
            $this->decide($this->server(), null, roles: [self::MODERATOR_ROLE]),
        );
    }

    public function testRefusesAMemberWithoutTheRole(): void
    {
        $server = $this->server();
        $right = new DiscordCommandRight($server, 'spieler.kick');
        $right->setRoleIds([self::MODERATOR_ROLE]);

        self::assertSame(
            CommandVerdict::NotAllowed,
            $this->decide($server, $right, roles: ['999999999999999999']),
        );
    }

    /** An empty allow-list is nobody, not everybody. */
    public function testAnEmptyRoleListAllowsNobody(): void
    {
        $server = $this->server();
        $right = new DiscordCommandRight($server, 'spieler.kick');
        $right->setRoleIds([]);

        self::assertSame(CommandVerdict::NotAllowed, $this->decide($server, $right, roles: []));
    }

    /**
     * A subcommand with no capability mapping is ungated in the panel's
     * terms, and ungated means refused rather than public.
     */
    public function testRefusesACommandThePanelDoesNotGate(): void
    {
        $server = $this->server();
        $right = new DiscordCommandRight($server, 'spieler.erfunden');
        $right->setRoleIds([self::MODERATOR_ROLE]);

        self::assertSame(
            CommandVerdict::UnknownCommand,
            $this->decide($server, $right, roles: [self::MODERATOR_ROLE], subcommand: 'erfunden'),
        );
    }

    /** A member of one guild must not command another's server. */
    public function testRefusesAnInteractionFromAnotherGuild(): void
    {
        $server = $this->server();
        $right = new DiscordCommandRight($server, 'spieler.kick');
        $right->setRoleIds([self::MODERATOR_ROLE]);

        self::assertSame(
            CommandVerdict::WrongGuild,
            $this->decide($server, $right, roles: [self::MODERATOR_ROLE], guild: '333333333333333333'),
        );
    }

    public function testRefusesEverythingWhenCommandsAreSwitchedOff(): void
    {
        $server = $this->server();
        $server->getDiscordConfig()?->setCommandsEnabled(false);

        $right = new DiscordCommandRight($server, 'spieler.kick');
        $right->setRoleIds([self::MODERATOR_ROLE]);

        self::assertSame(
            CommandVerdict::CommandsOff,
            $this->decide($server, $right, roles: [self::MODERATOR_ROLE]),
        );
    }

    public function testRefusesAServerWithNoDiscordLink(): void
    {
        self::assertSame(
            CommandVerdict::CommandsOff,
            $this->decide(new GameServer('Test'), null, roles: [self::MODERATOR_ROLE]),
        );
    }

    /**
     * The owner of a fresh guild has no roles to be given, because a
     * fresh guild has none — so requiring one locked them out of their
     * own bot. Discord's own permission bitfield settles it.
     */
    public function testAGuildAdministratorNeedsNoRoleAssignedHere(): void
    {
        self::assertSame(
            CommandVerdict::Allowed,
            $this->decide($this->server(), null, roles: [], permissions: (string) (1 << 3)),
        );
    }

    /** "Manage Server" is enough; it is what a co-owner usually holds. */
    public function testManageServerIsEnoughOnItsOwn(): void
    {
        self::assertSame(
            CommandVerdict::Allowed,
            $this->decide($this->server(), null, roles: [], permissions: (string) (1 << 5)),
        );
    }

    /**
     * An ordinary member's permissions must not open anything: this is
     * the bit that would turn the shortcut into a hole.
     */
    public function testAnOrdinaryMembersPermissionsGrantNothing(): void
    {
        // Send Messages, Read History, Add Reactions -- a normal member.
        $ordinary = (string) ((1 << 11) | (1 << 16) | (1 << 6));

        self::assertSame(
            CommandVerdict::NotAllowed,
            $this->decide($this->server(), null, roles: [], permissions: $ordinary),
        );
    }

    /** A bitfield that is not a number is not a permission. */
    public function testAMalformedPermissionFieldGrantsNothing(): void
    {
        foreach (['', 'administrator', '-8', '8.5'] as $nonsense) {
            self::assertSame(
                CommandVerdict::NotAllowed,
                $this->decide($this->server(), null, roles: [], permissions: $nonsense),
                sprintf('"%s" must not be read as a permission', $nonsense),
            );
        }
    }

    /**
     * The panel's own gate still applies: an administrator cannot run a
     * command the panel does not recognise.
     */
    public function testEvenAnAdministratorCannotRunAnUnmappedCommand(): void
    {
        self::assertSame(
            CommandVerdict::UnknownCommand,
            $this->decide(
                $this->server(),
                null,
                roles: [],
                subcommand: 'erfunden',
                permissions: (string) (1 << 3),
            ),
        );
    }

    /** Nor from another guild, nor when commands are switched off. */
    public function testAnAdministratorIsStillBoundByTheOtherChecks(): void
    {
        $server = $this->server();

        self::assertSame(
            CommandVerdict::WrongGuild,
            $this->decide($server, null, roles: [], guild: '999999999999999999', permissions: (string) (1 << 3)),
        );

        $server->getDiscordConfig()?->setCommandsEnabled(false);

        self::assertSame(
            CommandVerdict::CommandsOff,
            $this->decide($server, null, roles: [], permissions: (string) (1 << 3)),
        );
    }

    /** Only one verdict may let a command through. */
    public function testOnlyAllowedIsAllowed(): void
    {
        foreach (CommandVerdict::cases() as $verdict) {
            self::assertSame($verdict === CommandVerdict::Allowed, $verdict->isAllowed(), $verdict->value);
        }
    }

    /** A role id that is not one is dropped rather than stored. */
    public function testRoleIdsAreValidatedOnTheWayIn(): void
    {
        $right = new DiscordCommandRight($this->server(), 'spieler.kick');
        $right->setRoleIds([self::MODERATOR_ROLE, 'nonsense', '', '123']);

        self::assertSame([self::MODERATOR_ROLE], $right->getRoleIds());
    }

    private function server(): GameServer
    {
        $server = new GameServer('Test');
        new DiscordConfig($server, self::GUILD);

        return $server;
    }

    /** @param list<string> $roles */
    private function decide(
        GameServer $server,
        ?DiscordCommandRight $right,
        array $roles,
        string $subcommand = 'kick',
        string $guild = self::GUILD,
        string $permissions = '0',
    ): CommandVerdict {
        $rights = new class($right) implements CommandRights {
            public function __construct(private readonly ?DiscordCommandRight $right)
            {
            }

            public function forCommand(string $serverId, string $command): ?DiscordCommandRight
            {
                return $this->right;
            }
        };

        $interaction = new Interaction(
            InteractionType::APPLICATION_COMMAND,
            'spieler',
            $subcommand,
            ['spieler' => 'bob'],
            $guild,
            '444444444444444444',
            'somebody',
            $roles,
            $permissions,
        );

        return (new CommandAuthorisation($rights))->decide($server, $interaction);
    }
}
