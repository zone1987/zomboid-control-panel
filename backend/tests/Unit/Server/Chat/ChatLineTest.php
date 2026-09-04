<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Chat;

use App\Server\Chat\ChatLine;
use PHPUnit\Framework\TestCase;

/**
 * The shapes here come from ChatServer and ZLogger in build 42: the
 * stamp format is "dd-MM-yy HH:mm:ss.SSS", and a player's message is the
 * toString() of a ChatMessage, which escapes nothing.
 */
final class ChatLineTest extends TestCase
{
    public function testReadsAPlayerMessage(): void
    {
        $line = ChatLine::parse(
            "[04-09-26 20:41:49.474][info] Got message:ChatMessage{chat=UI_chat_main_tab_title_id, author='bob', text='hello everyone'}.",
        );

        self::assertSame(ChatLine::KIND_MESSAGE, $line->kind);
        self::assertSame('bob', $line->author);
        self::assertSame('hello everyone', $line->text);
        self::assertSame('04-09-26 20:41:49.474', $line->timestamp);
    }

    /**
     * The server logs the same message twice, the second time as it goes
     * out to the chat members. Keeping both would double every line.
     */
    public function testIgnoresTheSecondLogLineForTheSameMessage(): void
    {
        $line = ChatLine::parse(
            "[04-09-26 20:41:49.474][info] Message ChatMessage{chat=UI_chat_main_tab_title_id, author='bob', text='hello'} sent to chat (id = 1) members.",
        );

        self::assertSame(ChatLine::KIND_IGNORE, $line->kind);
    }

    /** toString() escapes nothing, so the text can contain its own delimiters. */
    public function testReadsAMessageContainingAnApostrophe(): void
    {
        $line = ChatLine::parse(
            "[04-09-26 20:41:49.474][info] Got message:ChatMessage{chat=t, author='bob', text='it's fine'}.",
        );

        self::assertSame('bob', $line->author);
        self::assertSame("it's fine", $line->text);
    }

    public function testReadsAMessageContainingABrace(): void
    {
        $line = ChatLine::parse(
            "[04-09-26 20:41:49.474][info] Got message:ChatMessage{chat=t, author='bob', text='use {this}'}.",
        );

        self::assertSame('use {this}', $line->text);
    }

    /** The nastiest case: the message quotes the log format itself. */
    public function testReadsAMessageThatQuotesTheLogFormat(): void
    {
        $line = ChatLine::parse(
            "[04-09-26 20:41:49.474][info] Got message:ChatMessage{chat=t, author='bob', text='look: ', text='fake''}.",
        );

        self::assertSame('bob', $line->author);
        self::assertSame("look: ', text='fake'", $line->text);
    }

    public function testKeepsUmlautsInAMessage(): void
    {
        $line = ChatLine::parse(
            "[04-09-26 20:41:49.474][info] Got message:ChatMessage{chat=t, author='Jürgen', text='Grüße!'}.",
        );

        self::assertSame('Jürgen', $line->author);
        self::assertSame('Grüße!', $line->text);
    }

    public function testReadsAServerBroadcast(): void
    {
        $line = ChatLine::parse("[04-09-26 20:45:31.274] Server alert message: 'Restart in 5' sent..");

        self::assertSame(ChatLine::KIND_BROADCAST, $line->kind);
        self::assertSame('Restart in 5', $line->text);
        self::assertNull($line->author);
    }

    public function testReadsAMessageBridgedFromDiscord(): void
    {
        $line = ChatLine::parse(
            "[04-09-26 20:45:31.274][info] Got message 'hi from discord' by author 'someone' from discord.",
        );

        self::assertSame(ChatLine::KIND_MESSAGE, $line->kind);
        self::assertSame('someone', $line->author);
        self::assertSame('hi from discord', $line->text);
    }

    public function testTreatsTheChatServersOwnChatterAsSystem(): void
    {
        $line = ChatLine::parse('[04-09-26 20:41:49.474][info] Say chat has id = 1.');

        self::assertSame(ChatLine::KIND_SYSTEM, $line->kind);
        self::assertSame('Say chat has id = 1.', $line->text);
    }

    public function testReadsALineWithNoLevel(): void
    {
        $line = ChatLine::parse('[04-09-26 20:41:49.474] Whisper chat starting....');

        self::assertSame('04-09-26 20:41:49.474', $line->timestamp);
        self::assertSame('Whisper chat starting....', $line->text);
    }

    public function testKeepsALineThatFollowsNoKnownShape(): void
    {
        $line = ChatLine::parse('something unexpected');

        self::assertSame(ChatLine::KIND_SYSTEM, $line->kind);
        self::assertNull($line->timestamp);
        self::assertSame('something unexpected', $line->text);
    }

    public function testKeepsAnEmptyMessageRatherThanMisreadingIt(): void
    {
        $line = ChatLine::parse(
            "[04-09-26 20:41:49.474][info] Got message:ChatMessage{chat=t, author='bob', text=''}.",
        );

        self::assertSame(ChatLine::KIND_MESSAGE, $line->kind);
        self::assertSame('', $line->text);
    }

    /**
     * Walks the shapes a real log holds, in the order the server writes
     * them, and checks that filtering the ignored ones leaves exactly the
     * message once.
     */
    public function testAWholeExchangeReducesToOneMessage(): void
    {
        $log = [
            '[04-09-26 20:41:49.474][info] Chat server successfully initialized.',
            "[04-09-26 20:42:01.100][info] Got message:ChatMessage{chat=t, author='bob', text='hello'}.",
            "[04-09-26 20:42:01.101][info] Message ChatMessage{chat=t, author='bob', text='hello'} sent to chat (id = 1) members.",
            "[04-09-26 20:42:30.000] Server alert message: 'Restart soon' sent..",
        ];

        $kept = array_values(array_filter(
            array_map(ChatLine::parse(...), $log),
            static fn (ChatLine $line): bool => $line->kind !== ChatLine::KIND_IGNORE,
        ));

        self::assertCount(3, $kept);
        self::assertSame(
            [ChatLine::KIND_SYSTEM, ChatLine::KIND_MESSAGE, ChatLine::KIND_BROADCAST],
            array_map(static fn (ChatLine $line): string => $line->kind, $kept),
        );
        self::assertSame('hello', $kept[1]->text);
    }

    public function testAlwaysKeepsTheOriginalLine(): void
    {
        $raw = '[04-09-26 20:41:49.474][info] Say chat has id = 1.';

        self::assertSame($raw, ChatLine::parse($raw)->raw);
    }
}
