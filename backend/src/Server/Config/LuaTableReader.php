<?php

declare(strict_types=1);

namespace App\Server\Config;

/**
 * Reads a `SandboxVars`-shaped Lua table into a flat map.
 *
 * A nested table becomes `Section.Name`, which is the key the game's own
 * settings screen uses (`ZombieLore.Speed`), and is why the section
 * cannot be dropped: `Farming` exists twice — as a skill-growth setting
 * and as an XP multiplier under `MultiplierConfig` — with different
 * bounds and different meanings.
 *
 * Character-by-character rather than by regex, because two cases in the
 * game's own default file break the obvious approaches:
 *
 *   - `WorldItemRemovalList` holds nine commas *inside* one quoted
 *     string. Splitting on commas truncates it after `Base.Hat`, which
 *     is the corruption that stopped a server booting for the reference
 *     panel.
 *   - `Apocalypse.lua` has five nested tables (`Basement`, `Map`,
 *     `ZombieLore`, `ZombieConfig`, `MultiplierConfig`) holding 86 of
 *     the values. Flattening them onto the top level loses them, and a
 *     mod puts its own values in exactly this shape.
 */
final class LuaTableReader
{
    /**
     * @return array<string, bool|float|int|string>
     */
    public function read(string $source): array
    {
        $values = [];
        $path = [];
        $length = strlen($source);
        $offset = 0;

        while ($offset < $length) {
            $character = $source[$offset];

            if ($character === '-' && substr($source, $offset, 4) === '--[[') {
                $end = strpos($source, ']]', $offset);
                $offset = $end === false ? $length : $end + 2;
                continue;
            }

            if ($character === '-' && substr($source, $offset, 2) === '--') {
                $end = strpos($source, "\n", $offset);
                $offset = $end === false ? $length : $end;
                continue;
            }

            if ($character === '}') {
                array_pop($path);
                ++$offset;
                continue;
            }

            if (preg_match('/\G([A-Za-z_][A-Za-z0-9_]*)\s*=\s*/A', $source, $match, 0, $offset) !== 1) {
                ++$offset;
                continue;
            }

            $key = $match[1];
            $offset += strlen($match[0]);

            if ($offset < $length && $source[$offset] === '{') {
                $path[] = $key;
                ++$offset;
                continue;
            }

            [$value, $offset] = $this->readValue($source, $offset);

            if ($value === null) {
                continue;
            }

            $values[implode('.', [...$path, $key])] = $value;
        }

        return $values;
    }

    /**
     * @return array{0: bool|float|int|string|null, 1: int}
     */
    private function readValue(string $source, int $offset): array
    {
        $length = strlen($source);

        if ($offset >= $length) {
            return [null, $offset];
        }

        if ($source[$offset] === '"' || $source[$offset] === "'") {
            return $this->readString($source, $offset);
        }

        // Everything up to the separator, which is safe here precisely
        // because a quoted value never reaches this branch.
        $end = $offset;

        while ($end < $length && !str_contains(",\n}", $source[$end])) {
            ++$end;
        }

        $raw = trim(substr($source, $offset, $end - $offset));

        return [$this->scalar($raw), $end];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function readString(string $source, int $offset): array
    {
        $quote = $source[$offset];
        $length = strlen($source);
        $text = '';
        ++$offset;

        while ($offset < $length) {
            $character = $source[$offset];

            if ($character === '\\' && $offset + 1 < $length) {
                $text .= match ($source[$offset + 1]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    default => $source[$offset + 1],
                };

                $offset += 2;
                continue;
            }

            if ($character === $quote) {
                return [$text, $offset + 1];
            }

            $text .= $character;
            ++$offset;
        }

        return [$text, $offset];
    }

    private function scalar(string $raw): bool|float|int|string|null
    {
        if ($raw === 'true') {
            return true;
        }

        if ($raw === 'false') {
            return false;
        }

        if ($raw === '' || $raw === 'nil') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        if (is_numeric($raw)) {
            return (float) $raw;
        }

        return $raw;
    }
}
