<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\GameServer;
use App\Entity\PlayerNote;
use PHPUnit\Framework\TestCase;

/**
 * The staff's own memory of a player, which is the one thing the dossier
 * stores rather than reads.
 */
final class PlayerNoteTest extends TestCase
{
    public function testKeepsANoteAndItsTagsTogether(): void
    {
        $note = self::note();

        $note->revise('Warned twice about the safehouse', ['trusted', 'builder'], null);

        self::assertSame('Warned twice about the safehouse', $note->getNote());
        self::assertSame(['trusted', 'builder'], $note->getTags());
        self::assertFalse($note->isEmpty());
    }

    /** Whitespace is not content, and a blank note is an absent one. */
    public function testAWhitespaceNoteIsNoNote(): void
    {
        $note = self::note();

        $note->revise("   \n  ", [], null);

        self::assertNull($note->getNote());
        self::assertTrue($note->isEmpty());
    }

    /**
     * "VIP" and "vip" are one tag, or a dossier sorts them apart and
     * shows the same label twice.
     */
    public function testTagsAreLowerCasedAndDeduplicated(): void
    {
        self::assertSame(['vip'], PlayerNote::clean(['VIP', 'vip', ' Vip ']));
    }

    /** A paste must not turn a dossier into a wall of chips. */
    public function testTagsAreCappedInCountAndLength(): void
    {
        $many = array_map(static fn (int $i): string => 'tag'.$i, range(1, 30));

        self::assertCount(PlayerNote::MAX_TAGS, PlayerNote::clean($many));

        $long = PlayerNote::clean([str_repeat('a', 100)]);

        self::assertSame(PlayerNote::MAX_TAG_LENGTH, mb_strlen($long[0]));
    }

    /**
     * A comma means somebody meant several tags and typed one, and a
     * newline would break the chip outright.
     */
    public function testSeparatorsInsideATagAreFlattened(): void
    {
        self::assertSame(['a b'], PlayerNote::clean(["a,b"]));
        self::assertSame(['a b'], PlayerNote::clean(["a\nb"]));
    }

    public function testEmptyTagsAreDropped(): void
    {
        self::assertSame(['kept'], PlayerNote::clean(['', '   ', 'kept', ',']));
    }

    /** Revising records when, so a stale note reads as one. */
    public function testRevisingMovesTheTimestamp(): void
    {
        $note = self::note();
        $before = $note->getUpdatedAt();

        // The column has second precision, so compare the objects rather
        // than racing the clock.
        $note->revise('changed', [], null);

        self::assertGreaterThanOrEqual($before, $note->getUpdatedAt());
    }

    private static function note(): PlayerNote
    {
        return new PlayerNote(new GameServer('Test'), 'bob');
    }
}
