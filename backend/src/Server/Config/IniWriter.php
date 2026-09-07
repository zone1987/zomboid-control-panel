<?php

declare(strict_types=1);

namespace App\Server\Config;

/**
 * Reads and edits a `server.ini` without disturbing anything else.
 *
 * Same rules as the sandbox writer: only the value after the `=` is
 * replaced, line endings and spacing survive, an unknown line is left
 * alone, and a duplicate key is refused rather than guessed at. Nothing
 * here removes a line.
 *
 * `parse_ini_string` is deliberately not used: it coerces `true`,
 * `false`, `on`, `off`, `none` and `null`, silently drops duplicate
 * keys, and treats `;` as a comment inside a value — and a real
 * server.ini holds `ChatStreams=s,r,a,w,y,sh,f,all` and welcome
 * messages with punctuation in them.
 */
final readonly class IniWriter
{
    /**
     * @return array<string, bool|float|int|string>
     */
    public function read(string $source): array
    {
        $values = [];

        foreach ($this->lines($source) as [$key, $raw]) {
            $values[$key] = $this->scalar($raw);
        }

        return $values;
    }

    /**
     * @param array<string, bool|float|int|string> $changes
     *
     * @throws ConfigWriteRefused when a key is absent or appears twice
     */
    public function apply(string $source, array $changes): string
    {
        foreach ($changes as $key => $value) {
            $source = $this->replace($source, $key, $value);
        }

        return $source;
    }

    private function replace(string $source, string $key, bool|float|int|string $value): string
    {
        // The value runs to the line ending, which is matched as a
        // group and put back: `$` with /m sits *before* a \r, so a
        // pattern anchored on it silently fails on a CRLF file.
        $pattern = sprintf(
            '/^([ \t]*%s[ \t]*=[ \t]*)([^\r\n]*)(\r?\n|$)/m',
            preg_quote($key, '/'),
        );

        $count = preg_match_all($pattern, $source, $matches);

        if ($count === 0) {
            throw new ConfigWriteRefused('config.unknownKeys', [$key]);
        }

        if ($count > 1) {
            throw new ConfigWriteRefused('config.duplicateKey', [$key]);
        }

        return (string) preg_replace_callback(
            $pattern,
            static fn (array $found): string => $found[1].self::render($value).$found[3],
            $source,
            1,
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function lines(string $source): array
    {
        $found = [];

        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            if (preg_match('/^\s*[#;]/', $line) === 1) {
                continue;
            }

            if (preg_match('/^\s*([A-Za-z0-9_.]+)\s*=(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $found[] = [$match[1], trim($match[2])];
        }

        return $found;
    }

    private function scalar(string $raw): bool|float|int|string
    {
        return match (true) {
            $raw === 'true' => true,
            $raw === 'false' => false,
            preg_match('/^-?\d+$/', $raw) === 1 => (int) $raw,
            is_numeric($raw) => (float) $raw,
            default => $raw,
        };
    }

    /** An INI value is bare: no quotes, and booleans lower case. */
    private static function render(bool|float|int|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_float($value)) {
            $rendered = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

            return $rendered === '' ? '0' : $rendered;
        }

        return (string) $value;
    }
}
