<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Chat;

use App\Server\Chat\ChatBroadcaster;
use PHPUnit\Framework\TestCase;

final class ChatBroadcasterTest extends TestCase
{
    public function testLeavesAnOrdinaryMessageAlone(): void
    {
        self::assertSame('Server restarts in 5 minutes', ChatBroadcaster::sanitise('Server restarts in 5 minutes'));
    }

    /**
     * The message is sent as a quoted RCON argument, so a quote inside it
     * would close the argument early and the rest would be read as
     * further arguments.
     */
    public function testReplacesQuotesThatWouldEndTheArgumentEarly(): void
    {
        self::assertSame("He said 'hello'", ChatBroadcaster::sanitise('He said "hello"'));
    }

    public function testFlattensNewlinesIntoSpaces(): void
    {
        self::assertSame('one two', ChatBroadcaster::sanitise("one\ntwo"));
    }

    public function testStripsControlCharacters(): void
    {
        self::assertSame('clean', ChatBroadcaster::sanitise("cl\x00ea\x07n"));
    }

    public function testCollapsesRunsOfWhitespace(): void
    {
        self::assertSame('a b', ChatBroadcaster::sanitise("a  \t  b"));
    }

    public function testTrimsTheEnds(): void
    {
        self::assertSame('text', ChatBroadcaster::sanitise('   text   '));
    }

    public function testKeepsAccentsAndUmlauts(): void
    {
        self::assertSame('Grüße für alle', ChatBroadcaster::sanitise('Grüße für alle'));
    }

    public function testCutsAMessageThatIsTooLong(): void
    {
        $long = str_repeat('a', 400);

        self::assertSame(ChatBroadcaster::MAX_LENGTH, mb_strlen(ChatBroadcaster::sanitise($long)));
    }

    /** Cutting by bytes would leave half a character behind. */
    public function testCutsByCharactersRatherThanBytes(): void
    {
        $result = ChatBroadcaster::sanitise(str_repeat('ü', 400));

        self::assertSame(ChatBroadcaster::MAX_LENGTH, mb_strlen($result));
        self::assertSame($result, mb_convert_encoding($result, 'UTF-8', 'UTF-8'));
    }

    public function testReturnsAnEmptyStringForAMessageOfOnlyWhitespace(): void
    {
        self::assertSame('', ChatBroadcaster::sanitise("  \n\t "));
    }
}
