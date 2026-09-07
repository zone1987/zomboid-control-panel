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
 *
 * **The channel matters and is parsed.** `ChatMessage::toString()` is
 * `ChatMessage{chat=<title>, author='..', text='..'}` where the title
 * comes from `ChatBase::getTitle()` — on a server that is the
 * translation key, `UI_chat_main_tab_title_id` and its four siblings,
 * because a server loads no translations. Without it the Discord bridge
 * could not tell general chat from faction, safehouse or whisper chat,
 * and mirroring those is a data-protection fault rather than a
 * cosmetic one.
 */
final readonly class ChatLine
{
    public const KIND_MESSAGE = 'message';
    public const KIND_BROADCAST = 'broadcast';
    public const KIND_SYSTEM = 'system';

    /** A duplicate of a message already reported, or noise. */
    public const KIND_IGNORE = 'ignore';

    /**
     * The five chat tabs the game defines, by the title a server logs.
     *
     * Read from the game's own `UI.json`: general, faction, safehouse,
     * radio and admin. A tab a future build adds is unknown here, and
     * unknown is treated as private.
     */
    public const CHANNEL_GENERAL = 'general';
    public const CHANNEL_FACTION = 'faction';
    public const CHANNEL_SAFEHOUSE = 'safehouse';
    public const CHANNEL_RADIO = 'radio';
    public const CHANNEL_ADMIN = 'admin';
    public const CHANNEL_DISCORD = 'discord';

    /** A tab the panel does not recognise, which is never mirrored. */
    public const CHANNEL_UNKNOWN = 'unknown';

    private const CHANNELS = [
        'UI_chat_main_tab_title_id' => self::CHANNEL_GENERAL,
        'UI_chat_faction_tab_title_id' => self::CHANNEL_FACTION,
        'UI_chat_safehouse_tab_title_id' => self::CHANNEL_SAFEHOUSE,
        'UI_chat_radio_tab_title_id' => self::CHANNEL_RADIO,
        'UI_chat_admin_tab_title_id' => self::CHANNEL_ADMIN,
        // A server with translations loaded logs the readable title
        // instead, so both spellings are recognised.
        'General' => self::CHANNEL_GENERAL,
        'Faction' => self::CHANNEL_FACTION,
        'Safehouse' => self::CHANNEL_SAFEHOUSE,
        'Radio' => self::CHANNEL_RADIO,
        'Admin chat' => self::CHANNEL_ADMIN,
    ];

    public function __construct(
        public string $kind,
        public ?string $timestamp,
        public ?string $author,
        public string $text,
        public string $raw,
        /** Which tab it was said in; null when the line carries none. */
        public ?string $channel = null,
    ) {
    }

    /**
     * Whether this line may be mirrored out of the game.
     *
     * **An allow-list.** Faction, safehouse, radio, admin and whisper
     * chat are private in the game and must stay private in Discord —
     * and a channel this build does not recognise is treated as private
     * too, because the safe direction for an unknown is silence.
     */
    public function isPublic(): bool
    {
        return $this->channel === self::CHANNEL_GENERAL;
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
            return new self(
                self::KIND_MESSAGE,
                $timestamp,
                $message['author'],
                $message['text'],
                $line,
                $message['channel'],
            );
        }

        // "Server alert message: 'Text' sent.."
        if (preg_match('/^Server alert message:\s*\'(.*)\'\s*sent\.*$/us', $body, $m) === 1) {
            return new self(self::KIND_BROADCAST, $timestamp, null, $m[1], $line);
        }

        // The Discord bridge has a shape of its own. Marked as coming
        // from Discord so a relay does not send it straight back.
        if (preg_match('/^Got message \'(.*)\' by author \'(.*)\' from discord\.?$/us', $body, $m) === 1) {
            return new self(self::KIND_MESSAGE, $timestamp, $m[2], $m[1], $line, self::CHANNEL_DISCORD);
        }

        return new self(self::KIND_SYSTEM, $timestamp, null, $body, $line);
    }

    /**
     * @return array{author: string, text: string, channel: string}|null
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

        // The channel is the first field and never holds a quote, so it
        // is read separately and safely. A line without it, or with one
        // this build does not know, counts as unknown -- which the
        // allow-list then refuses to mirror.
        $channel = self::CHANNEL_UNKNOWN;

        if (preg_match('/^Got message:ChatMessage\{chat=([^,]*),/us', $body, $found) === 1) {
            $channel = self::CHANNELS[trim($found[1])] ?? self::CHANNEL_UNKNOWN;
        }

        return ['author' => $m[1], 'text' => $m[2], 'channel' => $channel];
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
