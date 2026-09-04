<?php

declare(strict_types=1);

namespace App\Server\Chat;

/**
 * One line of the chat log, split into its parts.
 *
 * Zomboid writes several shapes into the same file, and most of them are
 * the chat server talking about itself rather than anyone speaking.
 */
final readonly class ChatLine
{
    public const KIND_MESSAGE = 'message';
    public const KIND_BROADCAST = 'broadcast';
    public const KIND_SYSTEM = 'system';

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

        // "[04-09-26 20:45:31.274] Server alert message: 'Text' sent.."
        if (preg_match('/^\[([^\]]+)\]\s*Server alert message:\s*\'(.*)\'\s*sent\.*$/u', $line, $m) === 1) {
            return new self(self::KIND_BROADCAST, $m[1], null, $m[2], $line);
        }

        // "[04-09-26 20:41:49.474][info] Chat server initialised."
        if (preg_match('/^\[([^\]]+)\]\[(\w+)\]\s*(.*)$/u', $line, $m) === 1) {
            return new self(self::KIND_SYSTEM, $m[1], null, $m[3], $line);
        }

        if (preg_match('/^\[([^\]]+)\]\s*(.*)$/u', $line, $m) === 1) {
            return new self(self::KIND_SYSTEM, $m[1], null, $m[2], $line);
        }

        return new self(self::KIND_SYSTEM, null, null, $line, $line);
    }
}
