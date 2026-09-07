<?php

declare(strict_types=1);

namespace App\Server\Config;

/**
 * Reads the game's own files and produces the configuration schema.
 *
 * Runs against a local Project Zomboid installation, never against a
 * server: the output describes what the *game build* offers, and it is
 * committed so the panel does not need the installation at run time.
 * What a particular server actually holds is read from that server's
 * own files — see the note on precedence in SandboxSchema.
 *
 * Six sources, none of them a guess:
 *
 *   media/lua/client/OptionScreens/ServerSettingsScreen.lua
 *      SettingsTable — the grouping the game shows the operator, for
 *      the INI *and* the sandbox. getPageName() is not the grouping:
 *      it is set only in addCustomOption, so it is null for every
 *      base-game option and carries a mod's page instead.
 *   media/lua/shared/Sandbox/Apocalypse.lua
 *      default values, with the nested tables kept nested
 *   javap -p zombie.SandboxOptions / zombie.network.ServerOptions
 *      the type of each option, from its declared field
 *   javap -p -c zombie.SandboxOptions
 *      the constructor: bounds, choice counts and setTranslation, whose
 *      key differs from the option name often enough to matter
 *      (Zombies -> ZombieCount)
 *   media/lua/shared/Translate/<LANG>/Sandbox.json
 *      names, per-choice labels and tooltips
 *   media/lua/shared/Translate/<LANG>/UI.json
 *      UI_ServerOption_<name>_tooltip for the INI
 */
final class SchemaExtractor
{
    /** The languages the panel itself speaks. */
    private const LANGUAGES = ['EN', 'DE'];

    /** Which key in the dump holds each translation file. */
    private const TRANSLATION_KEYS = ['Sandbox' => 'sandbox', 'UI' => 'ui'];

    /** The group for options the game's own screen does not show. */
    public const UNGROUPED = 'Advanced';

    /**
     * @param array{javap: array<string, array{fields: string, code: string}>, files: array<string, string>, buildId?: string} $dump
     */
    public function __construct(private readonly array $dump)
    {
    }

    /**
     * Loads a dump written by `backend/tools/dump-game-config.sh`.
     *
     * @throws \RuntimeException when the dump is absent or unusable
     */
    public static function fromDump(string $path): self
    {
        $raw = @file_get_contents($path);

        if (!is_string($raw)) {
            throw new \RuntimeException(sprintf(
                'no game dump at %s; run `bash backend/tools/dump-game-config.sh` on the host first',
                $path,
            ));
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['javap'], $decoded['files'])) {
            throw new \RuntimeException(sprintf('the game dump at %s is not in the expected shape', $path));
        }

        /** @var array{javap: array<string, array{fields: string, code: string}>, files: array<string, string>, buildId?: string} $decoded */
        return new self($decoded);
    }

    /**
     * @return array{
     *     buildId: string|null,
     *     sandbox: array<string, array<string, mixed>>,
     *     ini: array<string, array<string, mixed>>,
     *     sandboxGroups: list<array{name: string, options: list<string>}>,
     *     iniGroups: list<array{name: string, options: list<string>}>
     * }
     */
    public function extract(): array
    {
        $groups = $this->groups();
        $types = $this->sandboxTypes();
        $meta = $this->sandboxConstructors();
        $defaults = $this->sandboxDefaults();
        $text = $this->translations('Sandbox');

        $sandbox = [];

        foreach ($groups['Sandbox'] as $group) {
            foreach ($group['options'] as $key) {
                if (isset($sandbox[$key])) {
                    continue;
                }

                $sandbox[$key] = $this->sandboxOption($key, $group['name'], $types, $meta, $defaults, $text);
            }
        }

        // The settings screen does not show every option the game
        // defines: 13 are in the default file and in SandboxOptions but
        // on no page (Farming, StartYear, Version, the loot factors),
        // and LootItemRemovalList is in the class but not in the
        // default file. All of them are present on a real server, so
        // dropping them would make the editor unable to show -- or
        // worse, unable to preserve -- values that are actually there.
        $ungrouped = array_diff(array_keys($types + $defaults), array_keys($sandbox));

        foreach ($ungrouped as $key) {
            // Skip the section holders themselves, which are tables
            // rather than values.
            if (in_array($key, self::SECTIONS, true)) {
                continue;
            }

            $sandbox[$key] = $this->sandboxOption($key, self::UNGROUPED, $types, $meta, $defaults, $text);
        }

        $groups['Sandbox'][] = [
            'name' => self::UNGROUPED,
            'options' => array_values(array_diff(array_keys($sandbox), ...array_map(
                static fn (array $group): array => $group['options'],
                $groups['Sandbox'],
            ))),
        ];

        $ini = $this->iniOptions($groups['INI']);

        // Same as the sandbox: the game's settings screen lists 98 of
        // the 144 options ServerOptions defines, and a real server's
        // file holds all 144. An editor that cannot see the other 46
        // would delete them on save.
        $iniGroups = $groups['INI'];
        $ungroupedIni = array_diff(array_keys($this->iniConstructors()), array_keys($ini));

        if ($ungroupedIni !== []) {
            $iniGroups[] = ['name' => self::UNGROUPED, 'options' => array_values($ungroupedIni)];
            $ini = [...$ini, ...$this->iniOptions([end($iniGroups)])];
        }

        return [
            'buildId' => $this->buildId(),
            'sandbox' => $sandbox,
            'ini' => $ini,
            'sandboxGroups' => $groups['Sandbox'],
            'iniGroups' => $iniGroups,
        ];
    }

    /**
     * @param array<string, string>              $types
     * @param array<string, array<string, mixed>> $meta
     * @param array<string, mixed>               $defaults
     * @param array<string, array<string, string>> $text
     *
     * @return array<string, mixed>
     */
    private function sandboxOption(
        string $key,
        string $group,
        array $types,
        array $meta,
        array $defaults,
        array $text,
    ): array {
        $short = str_contains($key, '.') ? substr($key, strpos($key, '.') + 1) : $key;
        $declared = $meta[$key] ?? $meta[$short] ?? [];
        $type = $types[$key] ?? $types[$short] ?? null;

        // The translation key is not always the option name: the
        // constructor's setTranslation says which one to use.
        $translationKey = isset($declared['translation']) && is_string($declared['translation'])
            ? $declared['translation']
            : $short;

        $option = [
            'key' => $key,
            'section' => str_contains($key, '.') ? substr($key, 0, strpos($key, '.')) : null,
            'name' => $short,
            'type' => $type,
            'group' => $group,
            'default' => $defaults[$key] ?? null,
            'min' => $declared['min'] ?? null,
            'max' => $declared['max'] ?? null,
            'numValues' => $declared['numValues'] ?? null,
            'translationKey' => $translationKey,
            // The game generates this one per server (Rand.Next), so
            // there is no default to show and none to compare against.
            'defaultIsGenerated' => ($declared['defaultIsGenerated'] ?? false) === true,
            'labels' => [],
            'tooltips' => [],
            'choices' => [],
        ];

        foreach (self::LANGUAGES as $language) {
            $entries = $text[$language] ?? [];

            $option['labels'][$language] = $entries['Sandbox_'.$translationKey] ?? null;
            $option['tooltips'][$language] = $entries['Sandbox_'.$translationKey.'_tooltip'] ?? null;

            $choices = [];

            for ($index = 1; $index <= ($option['numValues'] ?? 0); ++$index) {
                $choices[$index] = $entries['Sandbox_'.$translationKey.'_option'.$index] ?? null;
            }

            if ($choices !== []) {
                $option['choices'][$language] = $choices;
            }
        }

        return $option;
    }

    /**
     * @param list<array{name: string, options: list<string>}> $groups
     *
     * @return array<string, array<string, mixed>>
     */
    private function iniOptions(array $groups): array
    {
        $types = $this->iniTypes();
        $meta = $this->iniConstructors();
        $text = $this->translations('UI');

        $options = [];

        foreach ($groups as $group) {
            foreach ($group['options'] as $name) {
                if (isset($options[$name])) {
                    continue;
                }

                $declared = $meta[$name] ?? [];

                $option = [
                    'key' => $name,
                    'name' => $name,
                    'type' => $types[$name] ?? null,
                    'group' => $group['name'],
                    'default' => $declared['default'] ?? null,
                    'min' => $declared['min'] ?? null,
                    'max' => $declared['max'] ?? null,
                    'numValues' => $declared['numValues'] ?? null,
                    'defaultIsGenerated' => ($declared['defaultIsGenerated'] ?? false) === true,
                    // The INI has no translated names, only tooltips.
                    'tooltips' => [],
                    'choices' => [],
                ];

                foreach (self::LANGUAGES as $language) {
                    $entries = $text[$language] ?? [];

                    $option['tooltips'][$language] =
                        $entries['UI_ServerOption_'.$name.'_tooltip'] ?? null;

                    $choices = $this->iniChoices($name, (int) ($option['numValues'] ?? 0), $entries);

                    if ($choices !== []) {
                        $option['choices'][$language] = $choices;
                    }
                }

                $options[$name] = $option;
            }
        }

        return $options;
    }

    /**
     * SettingsTable, which is the game's own grouping for both files.
     *
     * @return array{INI: list<array{name: string, options: list<string>}>, Sandbox: list<array{name: string, options: list<string>}>}
     */
    public function groups(): array
    {
        $lines = explode("\n", $this->read('settingsScreen'));

        $found = ['INI' => [], 'Sandbox' => []];
        $category = null;
        $depth = 0;

        foreach ($lines as $line) {
            if (preg_match('/^\s*name = "(INI|Sandbox)",\s*$/', $line, $match) === 1) {
                $category = $match[1];
                continue;
            }

            if ($category === null) {
                continue;
            }

            // A page's own name sits at the shallowest indent inside
            // pages; a setting's name is deeper and matched below.
            if (preg_match('/^(\s{1,4})name = "([A-Za-z0-9_]+)",\s*$/', $line, $match) === 1) {
                $found[$category][] = ['name' => $match[2], 'options' => []];
                continue;
            }

            // The presets page has a title and no name, and carries no
            // options of its own -- it is a panel, not a group.
            if (preg_match('/^\s*title = getText\(/', $line) === 1) {
                $found[$category][] = ['name' => 'Presets', 'options' => []];
                continue;
            }

            // A commented-out entry is not an option. Two are
            // (`-- { name = "LootRespawn" ... }`), and reading them
            // yields keys that exist in no class and no file.
            if (preg_match('/^\s*--/', $line) === 1) {
                continue;
            }

            // An advancedCombo's choices are `{ name = "Sandbox_High",
            // text = "50" }` -- labels for one option's presets, not
            // options. Reading them as options invents 60 that do not
            // exist. The value is not always quoted, hence `text =`
            // rather than `text = "`.
            if (str_contains($line, 'text = ')) {
                continue;
            }

            if (preg_match('/\{ name = "([A-Za-z0-9_.]+)"/', $line, $match) === 1 && $found[$category] !== []) {
                $last = count($found[$category]) - 1;

                if (!in_array($match[1], $found[$category][$last]['options'], true)) {
                    $found[$category][$last]['options'][] = $match[1];
                }
            }
        }

        return $found;
    }

    /**
     * The default values, with nested tables preserved as `Section.Name`.
     *
     * @return array<string, mixed>
     */
    public function sandboxDefaults(): array
    {
        return (new LuaTableReader())->read($this->read('defaults'));
    }

    /**
     * Each option's type, from the declared field on SandboxOptions.
     *
     * @return array<string, string>
     */
    public function sandboxTypes(): array
    {
        return $this->typesFrom($this->sandboxConstructors());
    }

    /** @return array<string, string> */
    public function iniTypes(): array
    {
        return $this->typesFrom($this->iniConstructors());
    }

    /**
     * The type comes from the factory the constructor called, never from
     * the field name: a field is camel-cased differently from its option
     * (`pvp` is `PVP`, `isPublic` is `Public`, `uPnp` is `UPnP`,
     * `udpPort` is `UDPPort`), so deriving one from the other loses
     * options silently. The constructor carries the real name as a
     * string literal.
     *
     * @param array<string, array<string, mixed>> $meta
     *
     * @return array<string, string>
     */
    private function typesFrom(array $meta): array
    {
        $types = [];

        foreach ($meta as $name => $entry) {
            if (isset($entry['kind']) && is_string($entry['kind'])) {
                $types[$name] = strtolower($entry['kind']);
            }
        }

        return $types;
    }

    /**
     * The five nested tables are inner classes with their own
     * constructors, so their 86 options are not in SandboxOptions'.
     * They are read separately and keyed `Section.Name`, which is how
     * the game's own settings screen names them.
     */
    private const SECTIONS = ['Basement', 'Map', 'ZombieLore', 'MultiplierConfig', 'ZombieConfig'];

    /**
     * A field is declared as `$<Type><Suffix> <camelCaseName>`, and the
     * option name is that field name with its first letter upper-cased.
     *
     * @return array<string, string>
     */
    private function fieldTypes(string $class, string $suffix): array
    {
        $out = $this->javap($class, 'fields');
        $types = [];

        $pattern = sprintf(
            '/\$(Boolean|Double|Integer|Enum|StrongEnum|String|Text)%s(?:<[^>]*>)?\s+([A-Za-z0-9_]+);/',
            preg_quote($suffix, '/'),
        );

        foreach (explode("\n", $out) as $line) {
            if (preg_match($pattern, $line, $match) !== 1) {
                continue;
            }

            // A StrongEnum is backed by a real Java enum but reaches the
            // file as an integer like any other enum.
            $type = strtolower($match[1] === 'StrongEnum' ? 'Enum' : $match[1]);

            $types[ucfirst($match[2])] = $type;
        }

        return $types;
    }

    /**
     * Bounds, choice counts and translation keys from the constructor.
     *
     * newEnumOption(name, numValues, default), newDoubleOption(name,
     * min, max, default), newIntegerOption(name, min, max, default),
     * newBooleanOption(name, default) — read from the bytecode because
     * the numbers exist nowhere else.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sandboxConstructors(): array
    {
        $meta = $this->constructors('zombie.SandboxOptions', 'Option');

        // A section's own constructor already names its options in full
        // ("ZombieLore.Speed"), so nothing is prefixed here. Its
        // setTranslation key is not derivable either -- ZombieLore.Speed
        // translates through "ZSpeed".
        foreach (self::SECTIONS as $section) {
            foreach ($this->constructors('zombie.SandboxOptions$'.$section, 'Option') as $name => $entry) {
                $meta[$name] = $entry;
            }
        }

        return $meta;
    }

    /** @return array<string, array<string, mixed>> */
    public function iniConstructors(): array
    {
        return $this->constructors('zombie.network.ServerOptions', 'ServerOption');
    }

    /** @return array<string, array<string, mixed>> */
    private function constructors(string $class, string $factorySuffix): array
    {
        $lines = explode("\n", $this->javap($class, 'code'));

        $found = [];
        $pending = [];

        foreach ($lines as $line) {
            // Operands accumulate until a factory call consumes them.
            if (preg_match('/\bldc\w*\s+#\d+\s+\/\/ String (.+)$/', $line, $match) === 1) {
                $pending[] = ['string', rtrim($match[1])];
                continue;
            }

            if (preg_match('/\b(?:bipush|sipush)\s+(-?\d+)\s*$/', $line, $match) === 1) {
                $pending[] = ['number', (float) $match[1]];
                continue;
            }

            if (preg_match('/\biconst_(m1|\d)\s*$/', $line, $match) === 1) {
                $pending[] = ['number', $match[1] === 'm1' ? -1.0 : (float) $match[1]];
                continue;
            }

            if (preg_match('/\bldc2?(?:_w)?\s+#\d+\s+\/\/ (?:double|float|long|int) ([-\d.eE+]+)/', $line, $match) === 1) {
                $pending[] = ['number', (float) $match[1]];
                continue;
            }

            if (preg_match('/\bdconst_(\d)\s*$/', $line, $match) === 1) {
                $pending[] = ['number', (float) $match[1]];
                continue;
            }

            // newEnumOption(name, Class, Enum): the choices and the
            // default live in a Java enum, not in the operand run, so
            // the class is noted and resolved after the loop.
            if (preg_match('/ldc\w*\s+#\d+\s+\/\/ class ([\w\/$]+)/', $line, $match) === 1) {
                $pending[] = ['class', str_replace('/', '.', $match[1])];
                continue;
            }

            if (preg_match('/getstatic\s+#\d+\s+\/\/ Field [\w\/$]+\.([A-Z_]+):/', $line, $match) === 1) {
                $pending[] = ['constant', $match[1]];
                continue;
            }

            // Two shapes, because the two classes differ:
            //   SandboxOptions calls factories (newEnumOption), and a
            //   section's inner class calls them on the outer instance;
            //   ServerOptions constructs directly (new BooleanServerOption).
            // Reading only the first shape leaves all 98 INI options
            // untyped, which is how this was found.
            $factory = '/Method (?:[\w\/$]+\.)?new(Boolean|Double|Integer|Enum|StrongEnum|String|Text)'
                .preg_quote($factorySuffix, '/').':/';
            $direct = '/Method [\w\/]+\$(Boolean|Double|Integer|Enum|StrongEnum|String|Text)'
                .preg_quote($factorySuffix, '/').'\."<init>":/';

            if (preg_match($factory, $line, $match) === 1 || preg_match($direct, $line, $match) === 1) {
                $this->recordOption($found, $match[1], $pending);
                $pending = [];
                continue;
            }

            // Marks *this* run as carrying a generated default without
            // ending it: ServerPlayerID passes Rand.Next through
            // Integer.toString, so the constructor call still follows.
            // It belongs to the run rather than to the object, or it
            // leaks into the next option -- which marked PVP and
            // Basement.SpawnFrequency as generated when neither is.
            if (str_contains($line, 'Method zombie/core/random/Rand.Next:')) {
                $pending[] = ['generated', true];
                continue;
            }

            if (str_contains($line, 'Method java/lang/Integer.toString:')) {
                continue;
            }

            if (preg_match('/Method .*\.setTranslation:/', $line) === 1) {
                $key = null;

                foreach (array_reverse($pending) as [$kind, $value]) {
                    if ($kind === 'string') {
                        $key = $value;
                        break;
                    }
                }

                if ($key !== null && $this->lastRecorded !== null) {
                    $found[$this->lastRecorded]['translation'] = $key;
                }

                $pending = [];
                continue;
            }

            // Anything else ends the run of operands, so a number from
            // an unrelated instruction cannot be read as a bound.
            if (preg_match('/^\s*\d+: (invoke|putfield|getfield|areturn|return|new|dup|aload|astore)/', $line) === 1) {
                if (preg_match('/^\s*\d+: (aload|dup|new)/', $line) !== 1) {
                    $pending = [];
                }
            }
        }

        return $found;
    }

    private ?string $lastRecorded = null;

    /**
     * @param array<string, array<string, mixed>> $found
     * @param list<array{0: string, 1: mixed}>    $pending
     */
    private function recordOption(array &$found, string $kind, array $pending): void
    {
        $name = null;
        $numbers = [];

        foreach ($pending as [$type, $value]) {
            if ($type === 'string' && $name === null) {
                $name = $value;
                $numbers = [];
                continue;
            }

            if ($type === 'number') {
                $numbers[] = $value;
            }
        }

        if ($name === null) {
            return;
        }

        $this->lastRecorded = $name;

        // A default produced at run time rather than taken from a
        // literal -- ResetID and ServerPlayerID call Rand.Next. The
        // panel must not present such a value as "the default", because
        // it differs on every server.
        $generated = false;

        foreach ($pending as [$kind_]) {
            if ($kind_ === 'generated') {
                $generated = true;
                break;
            }
        }
        $entry = $found[$name] ?? [];
        $entry['kind'] = $kind;


        // The argument lists differ per factory, so each is read on its
        // own terms rather than by position alone.
        if ($kind === 'StrongEnum' || ($kind === 'Enum' && $numbers === [])) {
            $entry = $this->strongEnum($entry, $pending);
        } elseif (($kind === 'String' || $kind === 'Text') && $pending !== []) {
            // The default is the second string argument, where there is
            // one: newStringOption(name, default, ...).
            $strings = array_values(array_filter(
                $pending,
                static fn (array $operand): bool => $operand[0] === 'string',
            ));

            if (isset($strings[1][1]) && is_string($strings[1][1])) {
                $entry['default'] = $strings[1][1];
            }
        } elseif ($kind === 'Boolean' && count($numbers) >= 1) {
            $entry['default'] = (bool) end($numbers);
        } elseif ($kind === 'Enum' && count($numbers) >= 2) {
            $entry['numValues'] = (int) $numbers[count($numbers) - 2];
            $entry['default'] = (int) $numbers[count($numbers) - 1];
            $entry['min'] = 1;
            $entry['max'] = $entry['numValues'];
        } elseif (($kind === 'Double' || $kind === 'Integer') && count($numbers) >= 3) {
            $entry['min'] = $numbers[count($numbers) - 3];
            $entry['max'] = $numbers[count($numbers) - 2];
            $entry['default'] = $numbers[count($numbers) - 1];

            if ($kind === 'Integer') {
                $entry['min'] = (int) $entry['min'];
                $entry['max'] = (int) $entry['max'];
                $entry['default'] = (int) $entry['default'];
            }
        }

        if ($generated) {
            // Last, because the branches above have just read the run's
            // literals: for ResetID the number in the run is Rand.Next's
            // *argument*, so leaving it would present 1000000000 as the
            // default on every server, which it never is. Bounds stay --
            // those are real.
            $entry['defaultIsGenerated'] = true;
            $entry['default'] = null;
        }

        $found[$name] = $entry;
    }

    /**
     * The choice labels for an INI enum.
     *
     * Ten of the eleven are the `AntiCheat*` options, and
     * `EnumServerOption::getValueTranslationByIndex` builds their key
     * from a **fixed** prefix in the constant pool —
     * `UI_ServerOption_AntiCheat_option<N>`, shared by all ten — rather
     * than from the option's own name. So "ban / kick / log / disabled"
     * exists once and is found only by knowing that. Looking for
     * `UI_ServerOption_AntiCheatSpeed_option1` finds nothing, which is
     * how these first reached the panel as the bare numbers 1 to 4.
     *
     * `BadWordPolicy` is the eleventh and the game translates no labels
     * for it, so it has none here either.
     *
     * @param array<string, string> $entries
     *
     * @return array<int, string|null>
     */
    private function iniChoices(string $name, int $numValues, array $entries): array
    {
        if ($numValues < 1) {
            return [];
        }

        $prefix = str_starts_with($name, 'AntiCheat') ? 'AntiCheat' : $name;
        $choices = [];
        $found = false;

        for ($index = 1; $index <= $numValues; ++$index) {
            $label = $entries['UI_ServerOption_'.$prefix.'_option'.$index] ?? null;
            $choices[$index] = $label;
            $found = $found || $label !== null;
        }

        return $found ? $choices : [];
    }

    /**
     * Resolves an option typed by a Java enum.
     *
     * `newEnumOption(name, Class, Enum)` carries its choices as the
     * class's constants and its default as one of them, so the count and
     * the index come from the enum rather than from a literal. Without
     * this, InjurySeverity and DamageToPlayerFromHitByACar reach the
     * panel as bare numbers with no bounds -- which is how the guard
     * test found them.
     *
     * @param array<string, mixed>             $entry
     * @param list<array{0: string, 1: mixed}> $pending
     *
     * @return array<string, mixed>
     */
    private function strongEnum(array $entry, array $pending): array
    {
        $class = null;
        $constant = null;

        foreach ($pending as [$kind, $value]) {
            if ($kind === 'class' && is_string($value)) {
                $class = $value;
            } elseif ($kind === 'constant' && is_string($value)) {
                $constant = $value;
            }
        }

        if ($class === null) {
            return $entry;
        }

        $constants = $this->enumConstants($class);

        if ($constants === []) {
            return $entry;
        }

        $entry['numValues'] = count($constants);
        $entry['min'] = 1;
        $entry['max'] = count($constants);
        $entry['enumClass'] = $class;
        $entry['enumConstants'] = $constants;

        // The game's enum options are one-based in the file, matching
        // getValueTranslationByIndex.
        $index = $constant === null ? false : array_search($constant, $constants, true);
        $entry['default'] = $index === false ? 1 : $index + 1;

        return $entry;
    }

    /**
     * @return list<string>
     */
    private function enumConstants(string $class): array
    {
        $fields = $this->dump['javap'][$class]['fields'] ?? null;

        if (!is_string($fields)) {
            return [];
        }

        preg_match_all(
            '/public static final \S+ ([A-Z][A-Z0-9_]*);/',
            $fields,
            $matches,
        );

        return $matches[1];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function translations(string $file): array
    {
        $out = [];

        foreach (self::LANGUAGES as $language) {
            $raw = $this->read(self::TRANSLATION_KEYS[$file].$language);

            // The game's translation files carry trailing commas, which
            // are legal Lua and not legal JSON.
            $raw = (string) preg_replace('/,(\s*[}\]])/', '$1', $raw);

            $decoded = json_decode($raw, true);

            $out[$language] = is_array($decoded) ? array_filter($decoded, 'is_string') : [];
        }

        return $out;
    }

    /**
     * The Steam build the schema was read from, so drift is detectable.
     * Absent for a non-Steam copy, which is a state rather than a fault.
     */
    public function buildId(): ?string
    {
        $steam = $this->dump['buildId'] ?? null;

        if (is_string($steam) && $steam !== '') {
            return $steam;
        }

        return $this->gameVersion();
    }

    /**
     * The game's own version, for a copy that did not come from Steam
     * and so has no appmanifest to stamp.
     *
     * `Core`'s static initialiser opens by pushing the major and minor
     * version -- `bipush 42; bipush 20` for B42.20 -- which is the only
     * place the number exists as a literal; `getVersionNumber()` builds
     * it at run time from these.
     */
    private function gameVersion(): ?string
    {
        $code = $this->dump['javap']['zombie.core.Core']['code'] ?? null;

        if (!is_string($code)) {
            return null;
        }

        $tail = substr($code, (int) strpos($code, 'static {}'));

        if (preg_match('/\b(?:bipush|sipush)\s+(\d+)\s*\n\s*\d+: (?:bipush|sipush)\s+(\d+)/', $tail, $match) !== 1) {
            return null;
        }

        return sprintf('B%d.%d', (int) $match[1], (int) $match[2]);
    }

    /** @param 'fields'|'code' $kind */
    private function javap(string $class, string $kind): string
    {
        $output = $this->dump['javap'][$class][$kind] ?? null;

        if (!is_string($output) || trim($output) === '') {
            throw new \RuntimeException(sprintf('the game dump holds no %s for %s', $kind, $class));
        }

        return $output;
    }

    private function read(string $name): string
    {
        $raw = $this->dump['files'][$name] ?? null;

        if (!is_string($raw)) {
            throw new \RuntimeException(sprintf('the game dump holds no "%s"', $name));
        }

        return $raw;
    }
}
