<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Config;

use App\Server\Config\LuaTableReader;
use PHPUnit\Framework\TestCase;

/**
 * Reading a SandboxVars file, with the three cases that corrupt one.
 *
 * Each of these is present in the game's own default template, so none
 * is an edge case: the reference panel split on commas, stopped a
 * server booting, and flattened the nested tables, losing 86 values.
 */
final class LuaTableReaderTest extends TestCase
{
    public function testKeepsCommasInsideAQuotedString(): void
    {
        $values = $this->read(<<<'LUA'
            return {
                WorldItemRemovalList = "Base.Hat, Base.Glasses, Base.Worm",
                Zombies = 4,
            }
            LUA);

        self::assertSame('Base.Hat, Base.Glasses, Base.Worm', $values['WorldItemRemovalList']);
        self::assertSame(4, $values['Zombies'], 'the key after a comma-bearing string is still read');
    }

    public function testNestsASectionRatherThanFlatteningIt(): void
    {
        $values = $this->read(<<<'LUA'
            return {
                Farming = 3,
                MultiplierConfig = {
                    Farming = 1.0,
                },
            }
            LUA);

        self::assertSame(3, $values['Farming']);
        self::assertSame(1.0, $values['MultiplierConfig.Farming']);
    }

    /**
     * The same name in two sections is two different options, with
     * different bounds and different meanings: Farming is a growth rate
     * of 1-5 at the top level and an XP multiplier of 0.001-1000 under
     * MultiplierConfig. Reading one as the other writes a wrong value.
     */
    public function testTheSectionIsPartOfTheKey(): void
    {
        $values = $this->read(<<<'LUA'
            return {
                ZombieLore = { Speed = 2 },
                ZombieConfig = { Speed = 9 },
            }
            LUA);

        self::assertSame(2, $values['ZombieLore.Speed']);
        self::assertSame(9, $values['ZombieConfig.Speed']);
        self::assertArrayNotHasKey('Speed', $values, 'a section key must never collapse to its short name');
    }

    public function testReadsEachScalarAsItsOwnType(): void
    {
        $values = $this->read(<<<'LUA'
            return {
                Yes = true,
                No = false,
                Whole = 42,
                Negative = -1,
                Fraction = 0.6,
                Text = "hello",
            }
            LUA);

        self::assertTrue($values['Yes']);
        self::assertFalse($values['No']);
        self::assertSame(42, $values['Whole']);
        self::assertSame(-1, $values['Negative']);
        self::assertSame(0.6, $values['Fraction']);
        self::assertSame('hello', $values['Text']);
    }

    public function testIgnoresComments(): void
    {
        $values = $this->read(<<<'LUA'
            return {
                -- Ghost = 1,
                Real = 2,
                --[[ Hidden = 3, ]]
            }
            LUA);

        self::assertSame(['Real' => 2], $values);
    }

    public function testKeepsAnEscapedQuoteInsideAString(): void
    {
        $values = $this->read('return { Message = "say \\"hi\\" now", Next = 1, }');

        self::assertSame('say "hi" now', $values['Message']);
        self::assertSame(1, $values['Next']);
    }

    /** @return array<string, bool|float|int|string> */
    private function read(string $lua): array
    {
        return (new LuaTableReader())->read($lua);
    }
}
