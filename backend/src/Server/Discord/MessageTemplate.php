<?php

declare(strict_types=1);

namespace App\Server\Discord;

/**
 * Fills `{token}` placeholders in an operator's own message text.
 *
 * Four rules, each one a bug the reference panel shipped:
 *
 * 1. **One pass with a callback.** Replacing tokens one after another
 *    lets a value containing `{player}` be substituted again, and a
 *    value containing `$1` be read as a backreference. One pass over
 *    the template, values never re-scanned.
 * 2. **Longest token first is not enough — match the whole name.**
 *    Replacing `{player}` inside `{playerCount}` produced
 *    "Steve Count". The pattern matches a complete `{name}`.
 * 3. **The values are escaped, the template is not.** The operator's
 *    own `**bold**` should work; a player calling themselves `**x**`
 *    should not smuggle formatting into somebody else's sentence. The
 *    reference escaped neither.
 * 4. **An unknown token stays as it is.** Blanking it hides a typo, and
 *    `{plyer}` surviving visibly is how the operator finds it.
 */
final readonly class MessageTemplate
{
    /**
     * Characters Discord reads as formatting anywhere in a line.
     *
     * `-`, `>` and `#` are deliberately absent: they are markdown only
     * at the *start* of a line — a list item, a quote, a heading — and
     * escaping them everywhere turned "Beispiel-Admin" into
     * "Beispiel\-Admin", which is uglier than the problem it solved.
     * The line-start cases are handled separately below.
     */
    private const MARKDOWN = ['\\', '*', '_', '~', '`', '|', '[', ']', '(', ')'];

    /**
     * @param array<string, scalar|null> $tokens
     */
    public function render(string $template, array $tokens): string
    {
        return (string) preg_replace_callback(
            '/\{([A-Za-z][A-Za-z0-9_.]*)\}/',
            static function (array $match) use ($tokens): string {
                // Not array_key_exists: a token present but null has
                // nothing to say, and printing "null" is worse than
                // printing nothing.
                if (!isset($tokens[$match[1]])) {
                    return array_key_exists($match[1], $tokens) ? '' : $match[0];
                }

                return self::escape((string) $tokens[$match[1]]);
            },
            $template,
        );
    }

    /**
     * Which tokens a template names that the event does not carry.
     *
     * Reported when the operator saves, so a typo is caught then rather
     * than discovered in a channel weeks later.
     *
     * @param list<string> $available
     *
     * @return list<string>
     */
    public function unknownTokens(string $template, array $available): array
    {
        preg_match_all('/\{([A-Za-z][A-Za-z0-9_.]*)\}/', $template, $matches);

        return array_values(array_unique(array_diff($matches[1], $available)));
    }

    /**
     * Escapes what Discord would read as formatting.
     *
     * The backslash goes first, or escaping the others would then escape
     * the backslashes this adds.
     */
    public static function escape(string $value): string
    {
        foreach (self::MARKDOWN as $character) {
            $value = str_replace($character, '\\'.$character, $value);
        }

        // Only at the start of a line, and only where the value itself
        // begins one -- a value dropped mid-sentence cannot become a
        // heading or a list item.
        $value = (string) preg_replace('/^(\s*)([-#>])/mu', '$1\\\\$2', $value);

        // Not markdown, and not escapable: a mention is a structural
        // reference Discord resolves before formatting. allowed_mentions
        // stops it pinging; this stops it rendering as a mention at all.
        return str_replace(['<@', '<#', '@everyone', '@here'], ['<\\@', '<\\#', '@\\everyone', '@\\here'], $value);
    }
}
