<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Config;

use App\Server\Config\ConfigWriteRefused;
use App\Server\Config\IniWriter;
use PHPUnit\Framework\TestCase;

/** Editing a server.ini without disturbing what was not asked about. */
final class IniWriterTest extends TestCase
{
    public function testReplacesOnlyTheValue(): void
    {
        $after = $this->apply("PVP=true\nPauseEmpty=false\n", ['PVP' => false]);

        self::assertSame("PVP=false\nPauseEmpty=false\n", $after);
    }

    /** Spacing is the operator's, not ours to normalise. */
    public function testKeepsHandWrittenSpacing(): void
    {
        self::assertSame("PVP  =  false\n", $this->apply("PVP  =  true\n", ['PVP' => false]));
    }

    public function testKeepsCarriageReturns(): void
    {
        $after = $this->apply("PVP=true\r\nMaxPlayers=32\r\n", ['MaxPlayers' => 16]);

        self::assertSame("PVP=true\r\nMaxPlayers=16\r\n", $after);
    }

    public function testLeavesEveryOtherLineAlone(): void
    {
        $before = "# a comment\nPVP=true\nSomeModKey=7\n\nMaxPlayers=32\n";

        $after = $this->apply($before, ['PVP' => false]);

        self::assertStringContainsString('# a comment', $after);
        self::assertStringContainsString('SomeModKey=7', $after);
        self::assertStringContainsString("\n\n", $after, 'the blank line is part of the file');
    }

    /** A value holding commas is why parse_ini_string is not used. */
    public function testReadsAValueHoldingCommas(): void
    {
        $values = (new IniWriter())->read("ChatStreams=s,r,a,w,y,sh,f,all\n");

        self::assertSame('s,r,a,w,y,sh,f,all', $values['ChatStreams']);
    }

    public function testReadsEachScalarAsItsType(): void
    {
        $values = (new IniWriter())->read("Yes=true\nNo=false\nWhole=32\nFraction=0.5\nText=hello\nEmpty=\n");

        self::assertTrue($values['Yes']);
        self::assertFalse($values['No']);
        self::assertSame(32, $values['Whole']);
        self::assertSame(0.5, $values['Fraction']);
        self::assertSame('hello', $values['Text']);
        self::assertSame('', $values['Empty'], 'an empty value is empty, not null');
    }

    public function testIgnoresComments(): void
    {
        $values = (new IniWriter())->read("# Ghost=1\n; Also=2\nReal=3\n");

        self::assertSame(['Real' => 3], $values);
    }

    /** Guessing between two assignments is how a value is lost. */
    public function testRefusesADuplicateKey(): void
    {
        $this->expectException(ConfigWriteRefused::class);

        $this->apply("PVP=true\nPVP=false\n", ['PVP' => false]);
    }

    /** Editing only: an absent key would be an addition. */
    public function testRefusesAKeyTheFileDoesNotHold(): void
    {
        $this->expectException(ConfigWriteRefused::class);

        $this->apply("PVP=true\n", ['Invented' => 1]);
    }

    /** A key inside a value is not an assignment to it. */
    public function testDoesNotMatchAKeyInsideAValue(): void
    {
        $before = "WelcomeMessage=Say PVP=true to nobody\nPVP=true\n";

        $after = $this->apply($before, ['PVP' => false]);

        self::assertStringContainsString('Say PVP=true to nobody', $after);
        self::assertStringContainsString("\nPVP=false", $after);
    }

    /** @param array<string, bool|float|int|string> $changes */
    private function apply(string $source, array $changes): string
    {
        return (new IniWriter())->apply($source, $changes);
    }
}
