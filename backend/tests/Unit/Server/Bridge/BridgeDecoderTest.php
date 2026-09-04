<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Bridge;

use PHPUnit\Framework\TestCase;

/**
 * The bridge's JSON reader is exercised in Lua, because Lua is what runs
 * on the game server -- see tests/Bridge/decode-test.lua. That test
 * carries a copy of the function, and a copy can drift.
 *
 * This checks it has not.
 */
final class BridgeDecoderTest extends TestCase
{
    private const BRIDGE = __DIR__.'/../../../../resources/bridge/ZomboidControlBridge.lua';
    private const LUA_TEST = __DIR__.'/../../../Bridge/decode-test.lua';

    public function testTheCopyInTheLuaTestMatchesTheBridge(): void
    {
        self::assertSame(
            $this->decoderIn(self::BRIDGE),
            $this->decoderIn(self::LUA_TEST),
            'The decoder in tests/Bridge/decode-test.lua no longer matches the bridge. '
            .'Copy it across again, then run the Lua test.',
        );
    }

    /** The shapes the panel sends have to keep parsing. */
    public function testTheLuaTestCoversTheEscapesThatBrokeTheFirstAttempt(): void
    {
        $test = (string) file_get_contents(self::LUA_TEST);

        self::assertStringContainsString('maskiertes Anfuehrungszeichen', $test);
        self::assertStringContainsString('maskierter Backslash', $test);
        self::assertStringContainsString('Feld nach maskiertem Quote', $test);
    }

    private function decoderIn(string $path): string
    {
        $source = (string) file_get_contents($path);
        $start = strpos($source, 'local function decodeFlatObject(text)');

        self::assertNotFalse($start, sprintf('No decoder found in %s.', basename($path)));

        // Ends at the first line that starts a new top-level definition.
        $rest = substr($source, $start);
        $end = strpos($rest, "\nlocal function ", 1);
        $comment = strpos($rest, "\n---", 1);

        if ($comment !== false && ($end === false || $comment < $end)) {
            $end = $comment;
        }

        return trim($end === false ? $rest : substr($rest, 0, $end));
    }
}
