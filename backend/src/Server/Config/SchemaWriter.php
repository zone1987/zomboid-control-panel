<?php

declare(strict_types=1);

namespace App\Server\Config;

/**
 * Writes the extracted schema out as PHP plus a provenance fixture.
 *
 * Two generated classes and one fixture. The fixture is what makes the
 * generation trustworthy: `ConfigSchemaTest` re-extracts and compares,
 * so a game update that moves a bound fails a test here rather than
 * clamping a value on somebody's server.
 */
final class SchemaWriter
{
    private const SANDBOX_CLASS = __DIR__.'/SandboxSchema.php';
    private const INI_CLASS = __DIR__.'/ServerIniSchema.php';
    private const FIXTURE = __DIR__.'/../../../tests/Fixtures/config-schema.json';

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    public function write(array $schema): array
    {
        $written = [];

        file_put_contents(self::SANDBOX_CLASS, $this->sandboxClass($schema));
        $written[] = self::SANDBOX_CLASS;

        file_put_contents(self::INI_CLASS, $this->iniClass($schema));
        $written[] = self::INI_CLASS;

        $fixture = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents(self::FIXTURE, $fixture."\n");
        $written[] = self::FIXTURE;

        return $written;
    }

    /** @param array<string, mixed> $schema */
    private function sandboxClass(array $schema): string
    {
        $header = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Server\Config;

            /**
             * The sandbox options this game build defines. Generated — do not edit.
             *
             * Written by `app:config:schema` from the installation's own files: the
             * grouping from `ServerSettingsScreen.lua`'s SettingsTable, the types
             * from the declared fields on `zombie.SandboxOptions`, the bounds and
             * choice counts from that class's constructor bytecode, and the labels,
             * choice labels and tooltips from `Translate/<LANG>/Sandbox.json`.
             *
             * **This describes the build, not a server.** The file on a particular
             * server is the truth: it is read over FTP, it may hold options this
             * build has never heard of (a mod's — the game provides for exactly
             * that through `initSandboxVars` and `isCustom`), and an option it holds
             * that is missing here is shown and preserved rather than dropped. A
             * schema-driven save would delete a mod's values, which is the one
             * failure this arrangement exists to prevent.
             *
             * `getPageName()` is deliberately not used for the grouping: it is set
             * only in `addCustomOption`, so it is null for every base-game option
             * and carries a mod's own page name instead.
             *
             * A key is always `Section.Name` where a section exists, because the
             * name alone is ambiguous: `Farming` is a skill-growth setting (1-5) at
             * the top level and an XP multiplier (0.001-1000) under
             * `MultiplierConfig`, and reading one as the other is a wrong value
             * written to a live server.
             */
            final class SandboxSchema
            {
            PHP;

        $lines = [$header];
        $lines[] = sprintf('    /** The Steam build these values were read from. */');
        $lines[] = sprintf(
            '    public const BUILD_ID = %s;',
            $schema['buildId'] === null ? 'null' : var_export((string) $schema['buildId'], true),
        );
        $lines[] = '';
        $lines[] = '    /** The order the game shows them in, which is the order we show. */';
        $lines[] = '    /** @var list<array{name: string, options: list<string>}> */';
        $lines[] = '    public const GROUPS = '.$this->export($schema['sandboxGroups'], 1).';';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @var array<string, array{key: string, section: string|null, name: string,';
        $lines[] = '     *     type: string|null, group: string, default: bool|float|int|string|null,';
        $lines[] = '     *     min: float|int|null, max: float|int|null, numValues: int|null,';
        $lines[] = '     *     translationKey: string, labels: array<string, string|null>,';
        $lines[] = '     *     tooltips: array<string, string|null>,';
        $lines[] = '     *     choices: array<string, array<int, string|null>>}>';
        $lines[] = '     */';
        $lines[] = '    public const OPTIONS = '.$this->export($schema['sandbox'], 1).';';
        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    /** @param array<string, mixed> $schema */
    private function iniClass(array $schema): string
    {
        $header = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Server\Config;

            /**
             * The server.ini options this game build defines. Generated — do not edit.
             *
             * Same provenance as `SandboxSchema`, with two differences that come
             * from the game rather than from us:
             *
             *   - `ServerOption` has no translated names, only tooltips, under the
             *     key `UI_ServerOption_<Name>_tooltip`. So the technical name is
             *     what the operator sees, with the tooltip explaining it.
             *   - the grouping is `SettingsTable[1]`, the same table the game's own
             *     server settings screen uses. It is taken from the game rather
             *     than invented here, which is what keeps settings that belong
             *     together in one place.
             *
             * The INI file on the server is still the truth: an option present
             * there and absent here is shown and preserved.
             */
            final class ServerIniSchema
            {
            PHP;

        $lines = [$header];
        $lines[] = '    /** @var list<array{name: string, options: list<string>}> */';
        $lines[] = '    public const GROUPS = '.$this->export($schema['iniGroups'], 1).';';
        $lines[] = '';
        $lines[] = '    /**';
        $lines[] = '     * @var array<string, array{key: string, name: string, type: string|null,';
        $lines[] = '     *     group: string, default: bool|float|int|string|null,';
        $lines[] = '     *     min: float|int|null, max: float|int|null, numValues: int|null,';
        $lines[] = '     *     tooltips: array<string, string|null>}>';
        $lines[] = '     */';
        $lines[] = '    public const OPTIONS = '.$this->export($schema['ini'], 1).';';
        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    /** var_export with the project's indentation and short array syntax. */
    private function export(mixed $value, int $depth): string
    {
        $pad = str_repeat('    ', $depth);

        if (!is_array($value)) {
            return var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $isList = array_is_list($value);
        $parts = [];

        foreach ($value as $key => $item) {
            $rendered = $this->export($item, $depth + 1);

            $parts[] = $isList
                ? sprintf('%s    %s,', $pad, $rendered)
                : sprintf('%s    %s => %s,', $pad, var_export($key, true), $rendered);
        }

        return "[\n".implode("\n", $parts)."\n".$pad.']';
    }
}
