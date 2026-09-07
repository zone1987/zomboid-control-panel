<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Discord;

use App\Security\Permission\Permission;
use App\Server\Discord\CommandCapabilities;
use App\Server\Discord\CommandCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Doing something through Discord must not be cheaper than in the panel.
 *
 * The reference panel's strongest idea, and the test is what makes it
 * hold: a command added without a permission fails here rather than
 * reaching Discord ungated.
 */
final class CommandCapabilitiesTest extends TestCase
{
    /** The one that matters: no subcommand may be unmapped. */
    public function testEverySubcommandNamesAPermission(): void
    {
        $unmapped = [];

        foreach (CommandCatalogue::names() as $name) {
            [$command, $subcommand] = explode('.', $name, 2);

            if (CommandCapabilities::of($command, $subcommand) === null) {
                $unmapped[] = $name;
            }
        }

        self::assertSame(
            [],
            $unmapped,
            'these subcommands would run with no permission at all: '.implode(', ', $unmapped),
        );
    }

    /** A mapping for a command that no longer exists is dead weight. */
    public function testEveryMappingNamesASubcommandThatExists(): void
    {
        $stray = array_values(array_diff(CommandCapabilities::names(), CommandCatalogue::names()));

        self::assertSame([], $stray, 'mappings for absent subcommands: '.implode(', ', $stray));
    }

    /** An unmapped name is refused, never waved through. */
    public function testAnUnknownCommandCostsNothingBecauseItIsRefused(): void
    {
        self::assertNull(CommandCapabilities::of('spieler', 'erfunden'));
        self::assertNull(CommandCapabilities::of('erfunden', 'kick'));
    }

    /**
     * The console is the panel's most dangerous control, and the
     * Discord route to it must cost exactly the same.
     */
    public function testTheConsoleCostsTheConsolePermission(): void
    {
        self::assertSame(Permission::UseConsole, CommandCapabilities::of('server', 'konsole'));
    }

    /** A ban through Discord costs what a ban in the panel costs. */
    public function testModerationCostsItsOwnPermission(): void
    {
        self::assertSame(Permission::KickPlayers, CommandCapabilities::of('spieler', 'kick'));
        self::assertSame(Permission::BanPlayers, CommandCapabilities::of('spieler', 'bannen'));
        self::assertSame(Permission::BanPlayers, CommandCapabilities::of('spieler', 'entbannen'));
        self::assertSame(Permission::GiveItems, CommandCapabilities::of('spieler', 'item'));
    }

    /** Reading is not writing: a listing must not need moderation. */
    public function testReadingOnlyCostsAReadingPermission(): void
    {
        foreach ([['spieler', 'liste'], ['spieler', 'info'], ['server', 'status']] as [$command, $sub]) {
            $permission = CommandCapabilities::of($command, $sub);

            self::assertContains(
                $permission,
                [Permission::ViewPlayers, Permission::ViewServers],
                sprintf('/%s %s should only need to read', $command, $sub),
            );
        }
    }
}
