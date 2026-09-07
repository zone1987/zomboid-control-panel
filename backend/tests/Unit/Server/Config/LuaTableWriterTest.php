<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Config;

use App\Server\Config\LuaTableReader;
use App\Server\Config\LuaTableWriter;
use PHPUnit\Framework\TestCase;

/**
 * Writing a SandboxVars file without damaging it.
 *
 * A wrong write here stops a real server booting, so the properties are
 * asserted rather than assumed: what the operator wrote by hand stays,
 * what a mod put there stays, and a value the panel does not understand
 * is never rewritten.
 */
final class LuaTableWriterTest extends TestCase
{
    public function testReplacesOnlyTheValue(): void
    {
        $before = "return {\n    Zombies = 4,\n    Distribution = 1,\n}\n";

        $after = $this->apply($before, ['Zombies' => 2]);

        self::assertSame("return {\n    Zombies = 2,\n    Distribution = 1,\n}\n", $after);
    }

    /** Hand-chosen spacing is the operator's, not ours to normalise. */
    public function testKeepsHandWrittenSpacing(): void
    {
        $after = $this->apply("return {\n    PVP   =   true,\n}\n", ['PVP' => false]);

        self::assertSame("return {\n    PVP   =   false,\n}\n", $after);
    }

    public function testKeepsCarriageReturns(): void
    {
        $after = $this->apply("return {\r\n    Zombies = 4,\r\n}\r\n", ['Zombies' => 1]);

        self::assertSame("return {\r\n    Zombies = 1,\r\n}\r\n", $after);
        self::assertStringContainsString("\r\n", $after, 'a CRLF file must stay CRLF');
    }

    /**
     * The mod protection: an option the schema has never heard of is
     * neither rewritten nor dropped. A save that renders the table from
     * the schema deletes exactly these.
     */
    public function testLeavesAnUnknownOptionUntouched(): void
    {
        $before = "return {\n    Zombies = 4,\n    SomeModOption = 7,\n    Comment = \"kept\",\n}\n";

        $after = $this->apply($before, ['Zombies' => 1]);

        self::assertStringContainsString('SomeModOption = 7', $after);
        self::assertStringContainsString('Comment = "kept"', $after);
    }

    public function testWritesIntoTheNamedSectionOnly(): void
    {
        $before = "return {\n    Farming = 3,\n    MultiplierConfig = {\n        Farming = 1.0,\n    },\n}\n";

        $after = $this->apply($before, ['MultiplierConfig.Farming' => 5.5]);

        self::assertStringContainsString("    Farming = 3,\n", $after, 'the top-level Farming is a different option');
        self::assertStringContainsString('Farming = 5.5', $after);
    }

    public function testWritesTheTopLevelKeyAndNotTheSectionOne(): void
    {
        $before = "return {\n    Farming = 3,\n    MultiplierConfig = {\n        Farming = 1.0,\n    },\n}\n";

        $after = $this->apply($before, ['Farming' => 1]);

        self::assertStringContainsString("    Farming = 1,\n", $after);
        self::assertStringContainsString('Farming = 1.0', $after, 'the multiplier must be untouched');
    }

    /** A whole float stays a float: 1 would change the option's type. */
    public function testAWholeFloatKeepsItsDecimal(): void
    {
        $after = $this->apply("return {\n    Rate = 0.6,\n}\n", ['Rate' => 2.0]);

        self::assertStringContainsString('Rate = 2.0', $after);
    }

    public function testEscapesAStringValue(): void
    {
        $after = $this->apply("return {\n    List = \"a\",\n}\n", ['List' => 'say "hi"']);

        self::assertStringContainsString('List = "say \\"hi\\""', $after);
    }

    /** A name inside a string is not an assignment to it. */
    public function testDoesNotMatchANameInsideAString(): void
    {
        $before = "return {\n    Removal = \"Base.Zombies, Base.Hat\",\n    Zombies = 4,\n}\n";

        $after = $this->apply($before, ['Zombies' => 1]);

        self::assertStringContainsString('Removal = "Base.Zombies, Base.Hat"', $after);
        self::assertStringContainsString("    Zombies = 1,\n", $after);
    }

    public function testRefusesAnAbsentOption(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no option "Missing"/');

        $this->apply("return {\n    Zombies = 4,\n}\n", ['Missing' => 1]);
    }

    /** Guessing between two assignments of one key is how data is lost. */
    public function testRefusesADuplicateKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/assigns "Zombies" 2 times/');

        $this->apply("return {\n    Zombies = 4,\n    Zombies = 2,\n}\n", ['Zombies' => 1]);
    }

    public function testRefusesAnAbsentSection(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no section "Nope"/');

        $this->apply("return {\n    Zombies = 4,\n}\n", ['Nope.Thing' => 1]);
    }

    /** The round trip is what proves the file is still the same file. */
    public function testTheResultReadsBackWithOnlyTheChangeApplied(): void
    {
        $before = <<<'LUA'
            return {
                Zombies = 4,
                WorldItemRemovalList = "Base.Hat, Base.Glasses",
                ZombieLore = {
                    Speed = 2,
                },
                ModThing = true,
            }
            LUA;

        $after = $this->apply($before, ['ZombieLore.Speed' => 3]);

        $reader = new LuaTableReader();
        $was = $reader->read($before);
        $is = $reader->read($after);

        self::assertSame(array_keys($was), array_keys($is), 'no key gained or lost');
        self::assertSame(3, $is['ZombieLore.Speed']);

        unset($was['ZombieLore.Speed'], $is['ZombieLore.Speed']);
        self::assertSame($was, $is, 'nothing but the asked-for value changed');
    }

    /** @param array<string, bool|float|int|string> $changes */
    private function apply(string $source, array $changes): string
    {
        return (new LuaTableWriter())->apply($source, $changes);
    }
}
