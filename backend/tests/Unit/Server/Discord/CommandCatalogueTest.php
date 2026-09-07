<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Discord;

use App\Server\Discord\CommandCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * The command declarations, against the limits Discord enforces.
 *
 * Discord rejects a registration that breaks any of these, and a
 * rejected registration means the bot has no commands at all — so
 * checking them here is cheaper than finding out from its API.
 */
final class CommandCatalogueTest extends TestCase
{
    public function testEveryCommandAndSubcommandFitsDiscordsNameRules(): void
    {
        foreach (CommandCatalogue::all() as $command) {
            $this->assertNameIsValid($command['name']);
            self::assertLessThanOrEqual(100, mb_strlen($command['description']));

            foreach ($command['options'] ?? [] as $subcommand) {
                $this->assertNameIsValid($subcommand['name']);
                self::assertLessThanOrEqual(100, mb_strlen($subcommand['description']));

                foreach ($subcommand['options'] ?? [] as $option) {
                    $this->assertNameIsValid($option['name']);
                    self::assertLessThanOrEqual(100, mb_strlen($option['description']));
                }
            }
        }
    }

    /** 25 subcommands per command, 25 options per subcommand. */
    public function testNothingExceedsTwentyFiveEntries(): void
    {
        foreach (CommandCatalogue::all() as $command) {
            self::assertLessThanOrEqual(25, count($command['options'] ?? []), $command['name']);

            foreach ($command['options'] ?? [] as $subcommand) {
                self::assertLessThanOrEqual(25, count($subcommand['options'] ?? []), $subcommand['name']);
            }
        }
    }

    /**
     * Discord requires required options before optional ones, and
     * rejects the whole registration otherwise.
     */
    public function testRequiredOptionsComeFirst(): void
    {
        foreach (CommandCatalogue::all() as $command) {
            foreach ($command['options'] ?? [] as $subcommand) {
                $seenOptional = false;

                foreach ($subcommand['options'] ?? [] as $option) {
                    if (($option['required'] ?? false) === false) {
                        $seenOptional = true;

                        continue;
                    }

                    self::assertFalse(
                        $seenOptional,
                        sprintf('%s: "%s" is required but follows an optional one', $subcommand['name'], $option['name']),
                    );
                }
            }
        }
    }

    /**
     * A choice list is capped at 25, which is why players, items and
     * vehicles use autocomplete instead.
     */
    public function testNoChoiceListExceedsTwentyFive(): void
    {
        foreach (CommandCatalogue::all() as $command) {
            foreach ($command['options'] ?? [] as $subcommand) {
                foreach ($subcommand['options'] ?? [] as $option) {
                    self::assertLessThanOrEqual(25, count($option['choices'] ?? []), $option['name']);
                }
            }
        }
    }

    /** A set too large for choices must be autocompleted, not typed. */
    public function testThePlayerAndItemOptionsAreAutocompleted(): void
    {
        $autocompleted = [];

        foreach (CommandCatalogue::all() as $command) {
            foreach ($command['options'] ?? [] as $subcommand) {
                foreach ($subcommand['options'] ?? [] as $option) {
                    if (($option['autocomplete'] ?? false) === true) {
                        $autocompleted[] = $command['name'].'.'.$subcommand['name'].'.'.$option['name'];
                    }
                }
            }
        }

        self::assertContains('spieler.kick.spieler', $autocompleted);
        self::assertContains('spieler.item.item', $autocompleted);
        self::assertContains('spieler.teleport.ziel', $autocompleted);
    }

    public function testEverySubcommandNameIsUniqueWithinItsCommand(): void
    {
        foreach (CommandCatalogue::all() as $command) {
            $names = array_map(
                static fn (array $option): string => $option['name'],
                $command['options'] ?? [],
            );

            self::assertSame(array_values(array_unique($names)), $names, $command['name']);
        }
    }

    /**
     * Discord's rule: lower case, 1 to 32 characters, no spaces. It
     * accepts letters beyond ASCII, which is why "stärke" is allowed.
     */
    private function assertNameIsValid(string $name): void
    {
        self::assertMatchesRegularExpression('/^[\p{Ll}\p{N}_-]{1,32}$/u', $name, $name);
    }
}
