<?php

declare(strict_types=1);

namespace App\Server\Discord;

/**
 * One message to post, with mentions switched off.
 *
 * **`allowed_mentions: {parse: []}` is not optional and is not a
 * setting.** Escaping the text is not enough: `<@&roleId>` is not
 * markdown, so a player calling themselves `<@&admins>` would ping a
 * role from inside a message the operator never wrote. The bot has no
 * reason to mention anybody, so it never can.
 */
final readonly class DiscordMessage
{
    /** Discord refuses a message body longer than this. */
    public const MAX_LENGTH = 2000;

    /** Discord refuses a message carrying more than this many. */
    public const MAX_EMBEDS = 10;

    /**
     * @param list<array<string, mixed>> $embeds cards to show under the text
     */
    public function __construct(
        public string $content,
        public array $embeds = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $payload = [
            'content' => self::fit($this->content),
            // Applies to the embed too: Discord parses mentions in an
            // embed's description exactly as it does in the content.
            'allowed_mentions' => ['parse' => []],
        ];

        if ($this->embeds !== []) {
            // Past the limit Discord refuses the whole message, so the
            // rest are dropped rather than the message lost.
            $payload['embeds'] = \array_slice($this->embeds, 0, self::MAX_EMBEDS);
        }

        return $payload;
    }

    /**
     * Trims to Discord's limit on a character boundary.
     *
     * A message refused for length is a message nobody sees, and a
     * truncated one at least says what happened.
     */
    private static function fit(string $content): string
    {
        if (mb_strlen($content) <= self::MAX_LENGTH) {
            return $content;
        }

        return mb_substr($content, 0, self::MAX_LENGTH - 1).'…';
    }
}
