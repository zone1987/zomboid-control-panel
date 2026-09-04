<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Logs;

use App\Server\Logs\LogTailer;
use PHPUnit\Framework\TestCase;

final class LogTailerTest extends TestCase
{
    public function testStartsAWindowBackWhenNothingHasBeenReadYet(): void
    {
        $window = LogTailer::windowFor(1_000_000, null);

        self::assertSame(1_000_000 - LogTailer::INITIAL_WINDOW_BYTES, $window['start']);
        self::assertFalse($window['rotated']);
    }

    public function testStartsAtTheBeginningOfAFileSmallerThanTheWindow(): void
    {
        self::assertSame(0, LogTailer::windowFor(500, null)['start']);
    }

    public function testContinuesFromTheRememberedOffset(): void
    {
        $window = LogTailer::windowFor(5000, 4000);

        self::assertSame(4000, $window['start']);
        self::assertFalse($window['truncated']);
        self::assertFalse($window['rotated']);
    }

    /**
     * A rotated log is shorter than the offset that was remembered from
     * the previous one. Following that offset would return nothing for
     * ever, so the read starts over.
     */
    public function testStartsOverWhenTheFileHasShrunk(): void
    {
        $window = LogTailer::windowFor(2000, 900_000);

        self::assertTrue($window['rotated']);
        self::assertSame(0, $window['start']);
    }

    public function testReportsRotationEvenWhenTheNewFileIsLarge(): void
    {
        $window = LogTailer::windowFor(1_000_000, 5_000_000);

        self::assertTrue($window['rotated']);
        self::assertSame(1_000_000 - LogTailer::INITIAL_WINDOW_BYTES, $window['start']);
    }

    public function testCapsHowMuchOnePollReturns(): void
    {
        $window = LogTailer::windowFor(10_000_000, 0);

        self::assertTrue($window['truncated']);
        self::assertSame(10_000_000 - LogTailer::MAX_CHUNK_BYTES, $window['start']);
    }

    public function testReadsNothingWhenTheFileHasNotGrown(): void
    {
        self::assertSame(5000, LogTailer::windowFor(5000, 5000)['start']);
    }

    /**
     * A remembered offset sits exactly where the previous read stopped,
     * which is a line boundary. Discarding up to the first newline there
     * would silently drop a whole line on every poll -- the fault that
     * hid a chat message written between two reads.
     */
    public function testKeepsTheFirstLineWhenContinuingFromARememberedOffset(): void
    {
        $window = LogTailer::windowFor(5000, 4000);

        self::assertSame(4000, $window['start']);
        self::assertFalse(
            LogTailer::startsMidLine($window['start'], 4000),
            'a remembered offset is a line boundary',
        );
    }

    public function testDiscardsThePartialLineWhenTheWindowWasChosenByByteCount(): void
    {
        // No offset at all: the read starts a window back from the end,
        // which lands wherever the byte count happens to fall.
        $window = LogTailer::windowFor(1_000_000, null);

        self::assertTrue(LogTailer::startsMidLine($window['start'], null));
    }

    public function testDiscardsThePartialLineWhenAnOversizedGapWasCappedBack(): void
    {
        // The caller asked to continue from 0, but the cap moved the
        // start forward, so it no longer sits on a line boundary.
        $window = LogTailer::windowFor(10_000_000, 0);

        self::assertTrue($window['truncated']);
        self::assertTrue(LogTailer::startsMidLine($window['start'], 0));
    }

    public function testSplitsAChunkIntoLines(): void
    {
        self::assertSame(
            ['first', 'second', 'third'],
            LogTailer::splitLines("first\nsecond\nthird\n", false),
        );
    }

    public function testHandlesWindowsLineEndings(): void
    {
        self::assertSame(['first', 'second'], LogTailer::splitLines("first\r\nsecond\r\n", false));
    }

    /**
     * A byte offset lands wherever the previous read stopped, which may be
     * halfway through a line or inside a multi-byte character. Discarding
     * up to the first newline fixes both.
     */
    public function testDiscardsThePartialLineAtTheStartOfAnOffsetRead(): void
    {
        self::assertSame(
            ['a whole line'],
            LogTailer::splitLines("f a broken line\na whole line\n", true),
        );
    }

    public function testKeepsEveryLineWhenReadingFromTheStartOfAFile(): void
    {
        self::assertSame(
            ['the first line', 'the second'],
            LogTailer::splitLines("the first line\nthe second\n", false),
        );
    }

    public function testReturnsNothingWhenAnOffsetChunkHasNoNewlineAtAll(): void
    {
        self::assertSame([], LogTailer::splitLines('half of a line still being written', true));
    }

    /**
     * The file ends wherever the server happened to have written to, so a
     * trailing line without a newline may still be incomplete. It arrives
     * on the next poll instead.
     */
    public function testDropsATrailingLineThatHasNoNewlineYet(): void
    {
        self::assertSame(['complete'], LogTailer::splitLines("complete\n", false));
    }

    public function testDropsBlankLines(): void
    {
        self::assertSame(['one', 'two'], LogTailer::splitLines("one\n\n   \ntwo\n", false));
    }

    public function testHandlesAnEmptyChunk(): void
    {
        self::assertSame([], LogTailer::splitLines('', false));
        self::assertSame([], LogTailer::splitLines('', true));
    }

    public function testKeepsAMultiByteLineIntact(): void
    {
        self::assertSame(
            ['Spieler „Müller" hat den Server betreten'],
            LogTailer::splitLines("Spieler „Müller\" hat den Server betreten\n", false),
        );
    }
}
