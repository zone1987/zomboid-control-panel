<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Rcon;

use App\Server\Rcon\CommandCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * Parsed against a reply captured from a live Build 42 server, so the
 * shapes here are the ones Zomboid actually produces.
 */
final class CommandCatalogueTest extends TestCase
{
    public function testFindsEveryCommandInALiveServerReply(): void
    {
        $catalogue = CommandCatalogue::parse($this->liveReply());

        self::assertCount(60, $catalogue->commands);
        self::assertContains('additem', array_column($catalogue->commands, 'name'));
        self::assertContains('worldgen', array_column($catalogue->commands, 'name'));
    }

    public function testReadsTheDescriptionWithoutTheUsageAndExample(): void
    {
        $command = $this->find('additem');

        self::assertStringContainsString('Give an item to a player', $command['description']);
        self::assertStringNotContainsString('Use:', $command['description']);
        self::assertStringNotContainsString('Example:', $command['description']);
    }

    public function testReadsTheUsageLine(): void
    {
        self::assertSame(
            '/additem "username" "module.item" count',
            $this->find('additem')['usage'],
        );
    }

    public function testReadsTheExample(): void
    {
        self::assertSame('/additem "rj" Base.Axe 5', $this->find('additem')['example']);
    }

    public function testNamesEachParameterInOrder(): void
    {
        self::assertSame(
            ['username', 'module.item', 'count'],
            array_column($this->find('additem')['parameters'], 'name'),
        );
    }

    public function testMarksWhichParametersMustBeQuoted(): void
    {
        $parameters = $this->find('additem')['parameters'];

        self::assertTrue($parameters[0]['quoted']);
        self::assertTrue($parameters[1]['quoted']);
        self::assertFalse($parameters[2]['quoted']);
    }

    /**
     * Zomboid states optionality in prose rather than in the syntax:
     * "If no username is given then you will receive the item yourself.
     * Count is optional."
     */
    public function testMarksParametersTheDescriptionCallsOptional(): void
    {
        $parameters = $this->find('additem')['parameters'];

        self::assertTrue($parameters[0]['optional'], 'username is optional');
        self::assertFalse($parameters[1]['optional'], 'the item is required');
        self::assertTrue($parameters[2]['optional'], 'count is optional');
    }

    /**
     * The entry for "kick" documents "/kickuser". The listed name is what
     * the server accepts, so only the arguments are taken from the usage.
     */
    public function testKeepsTheListedNameWhenTheUsageNamesAnother(): void
    {
        $command = $this->find('kick');

        self::assertSame('kick', $command['name']);
        self::assertSame(['username', '-r', 'reason'], array_column($command['parameters'], 'name'));
    }

    public function testHandlesACommandWithNoParameters(): void
    {
        self::assertSame([], $this->find('players')['parameters']);
    }

    public function testIgnoresTheHeadingAndAnyBlankLines(): void
    {
        $catalogue = CommandCatalogue::parse("List of server commands : \n\n* players : List players.\n\n");

        self::assertCount(1, $catalogue->commands);
    }

    public function testReportsAnEmptyCatalogueWhenTheReplyMakesNoSense(): void
    {
        self::assertTrue(CommandCatalogue::parse('Unknown command: help')->isEmpty());
    }

    public function testKeepsACommandWhoseLineWasCutOffMidExample(): void
    {
        // The live reply truncates addkey partway through its example.
        $command = $this->find('addkey');

        self::assertSame('addkey', $command['name']);
        self::assertNotSame([], $command['parameters']);
    }

    public function testLowercasesNamesSoTheyMatchWhatIsTyped(): void
    {
        // The server lists "addsteamid" but writes "/addSteamID" in usage.
        self::assertSame('addsteamid', $this->find('addsteamid')['name']);
    }

    public function testSortsCommandsByName(): void
    {
        $names = array_column(CommandCatalogue::parse($this->liveReply())->commands, 'name');
        $sorted = $names;
        sort($sorted);

        self::assertSame($sorted, $names);
    }

    /** @return array<string, mixed> */
    private function find(string $name): array
    {
        foreach (CommandCatalogue::parse($this->liveReply())->commands as $command) {
            if ($command['name'] === $name) {
                return $command;
            }
        }

        self::fail(sprintf('No command named "%s".', $name));
    }

    private function liveReply(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../Support/fixtures/zomboid-help-b42.txt');
    }
}
