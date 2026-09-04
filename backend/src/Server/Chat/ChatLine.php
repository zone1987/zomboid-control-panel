<?php

declare(strict_types=1);

namespace App\Server\Chat;

/**
 * One line of the chat log, split into its parts.
 *
 * Zomboid writes several shapes into the same file. A player's message
 * arrives as the toString() of a Java object -- it was meant as a debug
 * log, not as a protocol -- so parsing it takes some care:
 *
 *     [04-09-26 20:41:49.474][info] Got message:ChatMessage{chat=UI_chat_
 *     main_tab_title_id, author='bob', text='hello'}.
 *
 * The same message is logged twice, the second time as "Message ... sent
 * to chat (id = N) members". Only the first is kept, or every line would
 * appear twice.
 */
final readonly class ChatLine
{
    public const KIND_MESSAGE = 'message';
    public const KIND_BROADCAST = 'broadcast';
    public const KIND_SYSTEM = 'system';

    /** A duplicate of a message already reported, or noise. */
    public const KIND_IGNORE = 'ignore';

    public function __construct(
        public string $kind,
        public ?string $timestamp,
        public ?string $author,
        public string $text,
        public string $raw,
    ) {
    }

    public static function parse(string $line): self
    {
        $line = trim($line);

        [$timestamp, $body] = self::split($line);

        // The second log line for the same message. Dropped, or every
        // message would show up twice.
        if (str_starts_with($body, 'Message ChatMessage{')) {
            return new self(self::KIND_IGNORE, $timestamp, null, '', $line);
        }

        $message = self::parseMessage($body);

        if ($message !== null) {
            return new self(self::KIND_MESSAGE, $timestamp, $message['author'], $message['text'], $line);
        }

        // "Server alert message: 'Text' sent.."
        if (preg_match('/^Server alert message:\s*\'(.*)\'\s*sent\.*$/us', $body, $m) === 1) {
            return new self(self::KIND_BROADCAST, $timestamp, null, $m[1], $line);
        }

        // The Discord bridge has a shape of its own.
        if (preg_match('/^Got message \'(.*)\' by author \'(.*)\' from discord\.?$/us', $body, $m) === 1) {
            return new self(self::KIND_MESSAGE, $timestamp, $m[2], $m[1], $line);
        }

        return new self(self::KIND_SYSTEM, $timestamp, null, $body, $line);
    }

    /**
     * @return array{author: string, text: string}|null
     */
    private static function parseMessage(string $body): ?array
    {
        if (!str_starts_with($body, 'Got message:ChatMessage{')) {
            return null;
        }

        // toString() escapes nothing, so a message containing "', text='"
        // could fool a lazy pattern. The author runs to the first
        // "', text='" and the text to the last "'}" -- which is the
        // reading that survives quotes and braces inside the message.
        if (preg_match("/author='(.*?)', text='(.*)'\}\.?$/us", $body, $m) !== 1) {
            return null;
        }

        return ['author' => $m[1], 'text' => $m[2]];
    }

    /** @return array{0: string|null, 1: string} */
    private static function split(string $line): array
    {
        // "[stamp][level] body" or "[stamp] body"; the level is absent
        // when the server logs without one.
        if (preg_match('/^\[([^\]]+)\](?:\[\w+\])?\s*(.*)$/us', $line, $m) === 1) {
            return [$m[1], rtrim($m[2])];
        }

        return [null, $line];
    }
}
