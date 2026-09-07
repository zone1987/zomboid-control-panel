<?php

declare(strict_types=1);

namespace App\Server\Config;

use App\Entity\FtpConfig;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;

/**
 * Reads a server's settings file and describes each value it holds.
 *
 * **The file is the truth; the schema only describes it.** So the list
 * is built from what the file actually contains, and the schema is
 * consulted per key for its type, bounds, label and explanation. A key
 * the schema has never heard of — a mod's, and the game provides for
 * exactly that through `initSandboxVars` and `isCustom` — is listed
 * anyway, marked as unknown, with the type its value suggests.
 *
 * A schema-driven read would make such a value invisible, and a save
 * built from a schema-driven read would delete it. That is the one
 * failure this arrangement exists to prevent.
 */
final readonly class ConfigReader
{
    /**
     * `readTail` reads backwards from the end, so the ceiling has to
     * exceed the file or the beginning is missing. The live sandbox file
     * is 44 kB and the INI 15 kB.
     */
    private const MAX_BYTES = 1048576;

    public function __construct(
        private FileBrowserInterface $files,
        private LuaTableReader $lua,
    ) {
    }

    /**
     * @return array{values: list<array<string, mixed>>, raw: string, unknown: int}
     *
     * @throws StorageException
     */
    public function sandbox(FtpConfig $config, string $path): array
    {
        $raw = $this->files->readTail($config, $path, self::MAX_BYTES);
        $held = $this->lua->read($raw);

        return $this->describe($held, SandboxSchema::OPTIONS, $raw);
    }

    /**
     * @return array{values: list<array<string, mixed>>, raw: string, unknown: int}
     *
     * @throws StorageException
     */
    public function ini(FtpConfig $config, string $path): array
    {
        $raw = $this->files->readTail($config, $path, self::MAX_BYTES);

        return $this->describe($this->readIni($raw), ServerIniSchema::OPTIONS, $raw);
    }

    /**
     * An INI as the game writes it: `Key=value`, one per line, `#` for a
     * comment. Parsed by hand rather than with `parse_ini_string`, which
     * coerces `true`/`false`/`null`, drops duplicates silently and
     * chokes on unquoted values holding `;` — all three of which appear
     * in a real server's file.
     *
     * @return array<string, bool|float|int|string>
     */
    private function readIni(string $raw): array
    {
        $values = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^\s*[#;]/', $line) === 1) {
                continue;
            }

            if (preg_match('/^\s*([A-Za-z0-9_.]+)\s*=(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $values[$match[1]] = $this->scalar(trim($match[2]));
        }

        return $values;
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

    /**
     * @param array<string, bool|float|int|string> $held   what the file has
     * @param array<string, array<string, mixed>>  $schema what the build knows
     *
     * @return array{values: list<array<string, mixed>>, raw: string, unknown: int}
     */
    private function describe(array $held, array $schema, string $raw): array
    {
        $values = [];
        $unknown = 0;

        // The schema's keys come from the game's own code; a server's
        // file may differ in case (`VERSION` against `Version`), so a
        // case-insensitive index catches it without weakening the exact
        // match that comes first.
        $byLowerKey = array_change_key_case($schema, CASE_LOWER);

        foreach ($held as $key => $value) {
            $known = $schema[$key] ?? $byLowerKey[strtolower($key)] ?? null;

            if ($known === null) {
                ++$unknown;
            }

            $values[] = [
                'key' => $key,
                'section' => $known['section'] ?? (str_contains($key, '.')
                    ? substr($key, 0, strpos($key, '.'))
                    : null),
                'value' => $value,
                // From the file, so an option the schema does not know
                // still gets an editor of the right shape.
                'type' => $known['type'] ?? $this->typeOf($value),
                'known' => $known !== null,
                'group' => $known['group'] ?? null,
                'min' => $known['min'] ?? null,
                'max' => $known['max'] ?? null,
                'numValues' => $known['numValues'] ?? null,
                'labels' => $known['labels'] ?? null,
                'tooltips' => $known['tooltips'] ?? null,
                'choices' => $known['choices'] ?? null,
                'default' => $known['default'] ?? null,
                'defaultIsGenerated' => $known['defaultIsGenerated'] ?? false,
                // A choice outside what this build knows is never coerced
                // to a default: it is shown as-is and said to be
                // unrecognised, and it stays that way unless the operator
                // picks something else.
                'outOfRange' => $this->outOfRange($value, $known),
            ];
        }

        return ['values' => $values, 'raw' => $raw, 'unknown' => $unknown];
    }

    private function typeOf(bool|float|int|string $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'double',
            default => 'string',
        };
    }

    /** @param array<string, mixed>|null $known */
    private function outOfRange(bool|float|int|string $value, ?array $known): bool
    {
        if ($known === null || !is_numeric($value)) {
            return false;
        }

        $min = $known['min'] ?? null;
        $max = $known['max'] ?? null;

        if (!is_numeric($min) || !is_numeric($max)) {
            return false;
        }

        return $value < $min || $value > $max;
    }
}
