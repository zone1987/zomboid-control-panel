<?php

declare(strict_types=1);

namespace App\Server\Config;

/**
 * Replaces values in a `SandboxVars` file, touching nothing else.
 *
 * The file on the server is the truth: it may hold values this build
 * does not know (a mod's), values in a different order, hand-written
 * comments and hand-chosen spacing. So a save edits the values that
 * changed **in place** and leaves every byte around them alone, rather
 * than rendering the table afresh from the schema.
 *
 * Four properties, each one a bug the reference panel shipped and fixed:
 *
 *   - line endings survive (their every save silently turned CRLF to LF)
 *   - only the value is replaced, never the whole line, so a
 *     hand-written `PVP = true` keeps its spacing
 *   - a key the schema does not know is never rewritten and never
 *     dropped — that is the mod protection
 *   - a duplicate key is refused rather than guessed at
 */
final class LuaTableWriter
{
    /**
     * @param array<string, bool|float|int|string> $changes keyed as `Section.Name`
     *
     * @throws \RuntimeException when a key is absent or ambiguous
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
        $section = str_contains($key, '.') ? substr($key, 0, strpos($key, '.')) : null;
        $name = $section === null ? $key : substr($key, strpos($key, '.') + 1);

        $region = $this->region($source, $section);
        $matches = $this->assignments($source, $name, $region);

        if ($matches === []) {
            throw new \RuntimeException(sprintf('the file has no option "%s"', $key));
        }

        if (count($matches) > 1) {
            throw new \RuntimeException(sprintf(
                'the file assigns "%s" %d times; refusing to guess which one is meant',
                $key,
                count($matches),
            ));
        }

        [$valueStart, $valueEnd] = $matches[0];

        return substr($source, 0, $valueStart).$this->render($value).substr($source, $valueEnd);
    }

    /**
     * Where a section's body begins and ends, so `Farming` inside
     * `MultiplierConfig` is never confused with the top-level one.
     *
     * @return array{0: int, 1: int}
     */
    private function region(string $source, ?string $section): array
    {
        if ($section === null) {
            return [0, strlen($source)];
        }

        if (preg_match('/(?<![A-Za-z0-9_.])'.preg_quote($section, '/').'\s*=\s*\{/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            throw new \RuntimeException(sprintf('the file has no section "%s"', $section));
        }

        $offset = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $length = strlen($source);

        while ($offset < $length && $depth > 0) {
            $character = $source[$offset];

            if ($character === '"' || $character === "'") {
                $offset = $this->skipString($source, $offset);
                continue;
            }

            if ($character === '{') {
                ++$depth;
            } elseif ($character === '}') {
                --$depth;
            }

            ++$offset;
        }

        return [$match[0][1], $offset];
    }

    /**
     * Every assignment of a name within a region, as value boundaries.
     *
     * Depth is tracked so a top-level key is not matched inside a
     * nested table, and strings are skipped so a name appearing inside
     * `WorldItemRemovalList` is not mistaken for an assignment.
     *
     * @param array{0: int, 1: int} $region
     *
     * @return list<array{0: int, 1: int}>
     */
    private function assignments(string $source, string $name, array $region): array
    {
        [$from, $to] = $region;
        $found = [];
        $offset = $from;
        $depth = 0;

        while ($offset < $to) {
            $character = $source[$offset];

            if ($character === '"' || $character === "'") {
                $offset = $this->skipString($source, $offset);
                continue;
            }

            if ($character === '{') {
                ++$depth;
                ++$offset;
                continue;
            }

            if ($character === '}') {
                --$depth;
                ++$offset;
                continue;
            }

            if ($source[$offset] === '-' && substr($source, $offset, 2) === '--') {
                $end = strpos($source, "\n", $offset);
                $offset = $end === false ? $to : $end;
                continue;
            }

            $pattern = '/\G(?<![A-Za-z0-9_.])'.preg_quote($name, '/').'\s*=\s*/A';

            if (preg_match($pattern, $source, $match, 0, $offset) !== 1) {
                ++$offset;
                continue;
            }

            $valueStart = $offset + strlen($match[0]);

            // Only assignments at this region's own level. Depth 1 in
            // both cases: the top level sits inside `return {`, and a
            // section inside its own `Name = {`. Verified against
            // Apocalypse.lua, where `Farming` is depth 1 (3, skill
            // growth) and depth 2 (1.0, the XP multiplier).
            if ($depth === 1) {
                $found[] = [$valueStart, $this->valueEnd($source, $valueStart)];
            }

            $offset = $valueStart;
        }

        return $found;
    }

    private function valueEnd(string $source, int $offset): int
    {
        $length = strlen($source);

        if ($offset < $length && ($source[$offset] === '"' || $source[$offset] === "'")) {
            return $this->skipString($source, $offset);
        }

        while ($offset < $length && !str_contains(",\n}", $source[$offset])) {
            ++$offset;
        }

        return $offset;
    }

    /** @return int the offset just past the closing quote */
    private function skipString(string $source, int $offset): int
    {
        $quote = $source[$offset];
        $length = strlen($source);
        ++$offset;

        while ($offset < $length) {
            if ($source[$offset] === '\\') {
                $offset += 2;
                continue;
            }

            if ($source[$offset] === $quote) {
                return $offset + 1;
            }

            ++$offset;
        }

        return $offset;
    }

    /** Lua literals: a string is quoted and escaped, a number is not. */
    private function render(bool|float|int|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // A whole float still has to read as a float: the game's own
            // file writes 1.0, and 1 would change the option's type.
            $rendered = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

            return str_contains($rendered, '.') ? $rendered : $rendered.'.0';
        }

        return '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value).'"';
    }
}
