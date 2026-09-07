<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Config;

use App\Server\Config\SandboxSchema;
use App\Server\Config\ServerIniSchema;
use PHPUnit\Framework\TestCase;

/**
 * The generated schema, checked against the fixture it was written from.
 *
 * The generator needs a game installation and a JDK, so it cannot run
 * here. What runs here is the comparison: the committed classes must
 * match the committed fixture, and both must hold the properties the
 * editor relies on. A game update that moves a bound therefore surfaces
 * as a red test after regeneration rather than as a clamped value on
 * somebody's server.
 */
final class ConfigSchemaTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../../Fixtures/config-schema.json';

    public function testTheClassesMatchTheFixture(): void
    {
        $fixture = self::fixture();

        self::assertSame(
            $fixture['sandbox'],
            self::normalise(SandboxSchema::OPTIONS),
            'SandboxSchema and the fixture disagree; regenerate both with app:config:schema',
        );

        self::assertSame(
            $fixture['ini'],
            self::normalise(ServerIniSchema::OPTIONS),
            'ServerIniSchema and the fixture disagree; regenerate both with app:config:schema',
        );
    }

    public function testEverySandboxOptionBelongsToExactlyOneGroup(): void
    {
        $seen = [];

        foreach (SandboxSchema::GROUPS as $group) {
            foreach ($group['options'] as $key) {
                self::assertArrayNotHasKey($key, $seen, sprintf(
                    '"%s" is in both "%s" and "%s"',
                    $key,
                    $seen[$key] ?? '',
                    $group['name'],
                ));

                $seen[$key] = $group['name'];
            }
        }

        foreach (array_keys(SandboxSchema::OPTIONS) as $key) {
            self::assertArrayHasKey($key, $seen, sprintf('"%s" is in no group at all', $key));
        }
    }

    /**
     * The user's requirement, as a test: settings that belong together
     * stay together. The grouping is the game's own SettingsTable, so
     * this asserts we did not lose an option on the way out of it.
     */
    public function testEveryIniOptionBelongsToExactlyOneGroup(): void
    {
        $seen = [];

        foreach (ServerIniSchema::GROUPS as $group) {
            foreach ($group['options'] as $key) {
                self::assertArrayNotHasKey($key, $seen, sprintf('"%s" is in two groups', $key));
                $seen[$key] = $group['name'];
            }
        }

        foreach (array_keys(ServerIniSchema::OPTIONS) as $key) {
            self::assertArrayHasKey($key, $seen, sprintf('"%s" is in no group at all', $key));
        }
    }

    /** A value shown without its type cannot be edited safely. */
    public function testEveryOptionHasAType(): void
    {
        $untyped = [];

        foreach (SandboxSchema::OPTIONS as $key => $option) {
            // Version is the file format's own number, not a setting.
            if ($option['type'] === null && $key !== 'Version') {
                $untyped[] = $key;
            }
        }

        self::assertSame([], $untyped, 'sandbox options with no type: '.implode(', ', $untyped));

        $untypedIni = array_keys(array_filter(
            ServerIniSchema::OPTIONS,
            static fn (array $option): bool => $option['type'] === null,
        ));

        self::assertSame([], $untypedIni, 'ini options with no type: '.implode(', ', $untypedIni));
    }

    /** A numeric field without bounds is a text box that accepts anything. */
    public function testEveryNumericOptionHasBounds(): void
    {
        foreach ([SandboxSchema::OPTIONS, ServerIniSchema::OPTIONS] as $set) {
            foreach ($set as $key => $option) {
                if (!in_array($option['type'], ['integer', 'double', 'enum'], true)) {
                    continue;
                }

                self::assertNotNull($option['min'], sprintf('"%s" has no minimum', $key));
                self::assertNotNull($option['max'], sprintf('"%s" has no maximum', $key));
            }
        }
    }

    /** An enum shown as a number is unusable, so it needs its labels. */
    public function testEveryEnumHasAChoiceLabelPerValue(): void
    {
        foreach (SandboxSchema::OPTIONS as $key => $option) {
            if ($option['type'] !== 'enum') {
                continue;
            }

            self::assertNotNull($option['numValues'], sprintf('"%s" is an enum with no value count', $key));

            $choices = $option['choices']['EN'] ?? [];

            self::assertCount(
                $option['numValues'],
                $choices,
                sprintf('"%s" has %d values but %d labels', $key, $option['numValues'], count($choices)),
            );
        }
    }

    /**
     * The user asked for the game's own explanations to be shown, so
     * this records how many there are. It is a floor rather than an
     * exact count: a game update adding tooltips must not fail.
     */
    public function testTheGamesExplanationsAreCarried(): void
    {
        $withTooltip = array_filter(
            SandboxSchema::OPTIONS,
            static fn (array $option): bool => ($option['tooltips']['DE'] ?? null) !== null,
        );

        // 243 of the 270 carry one. The other 27 have no tooltip in the
        // game either -- DayLength has 27 choice labels and no
        // explanation -- so this is the game's own coverage, not ours.
        self::assertGreaterThanOrEqual(
            240,
            count($withTooltip),
            'the German sandbox tooltips are no longer being carried through',
        );

        $iniTooltips = array_filter(
            ServerIniSchema::OPTIONS,
            static fn (array $option): bool => ($option['tooltips']['DE'] ?? null) !== null,
        );

        // 86 of the 98. The other 12 (UDPPort, the four Backups
        // options, War, SpeedLimit, the two Chat limits and the three
        // disguise ones) have no tooltip in the game in *any* language,
        // so the panel has to explain those itself.
        self::assertGreaterThanOrEqual(85, count($iniTooltips), 'the German INI tooltips are no longer carried');
    }

    /**
     * `Farming` is the case that makes the section part of the key: two
     * options, one name, different bounds and opposite scales.
     */
    public function testASectionedKeyIsDistinctFromItsTopLevelNamesake(): void
    {
        self::assertArrayHasKey('Farming', SandboxSchema::OPTIONS);
        self::assertArrayHasKey('MultiplierConfig.Farming', SandboxSchema::OPTIONS);

        self::assertSame('enum', SandboxSchema::OPTIONS['Farming']['type']);
        self::assertSame('double', SandboxSchema::OPTIONS['MultiplierConfig.Farming']['type']);
        self::assertSame('MultiplierConfig', SandboxSchema::OPTIONS['MultiplierConfig.Farming']['section']);
        self::assertNull(SandboxSchema::OPTIONS['Farming']['section']);
    }

    /**
     * Two INI options are generated per server (Rand.Next), so they
     * have no default to compare against. Presenting the literal from
     * the bytecode as "the default" would be a value that is never true.
     */
    public function testARunTimeGeneratedDefaultIsMarkedAndNotInvented(): void
    {
        foreach (['ResetID', 'ServerPlayerID'] as $key) {
            self::assertTrue(
                ServerIniSchema::OPTIONS[$key]['defaultIsGenerated'],
                sprintf('"%s" is generated per server and must say so', $key),
            );

            self::assertNull(
                ServerIniSchema::OPTIONS[$key]['default'],
                sprintf('"%s" must not claim a default it does not have', $key),
            );
        }

        self::assertFalse(
            ServerIniSchema::OPTIONS['PVP']['defaultIsGenerated'],
            'PVP has a real default; the generated flag must not leak between options',
        );
        self::assertTrue(ServerIniSchema::OPTIONS['PVP']['default']);
    }

    /**
     * The names that cannot be derived from the field, each one a bug
     * caught by generating rather than typing.
     */
    public function testOptionsWhoseNameDiffersFromTheirFieldArePresent(): void
    {
        foreach (['PVP', 'Public', 'UDPPort', 'UPnP'] as $key) {
            self::assertArrayHasKey($key, ServerIniSchema::OPTIONS, sprintf('"%s" was lost', $key));
        }
    }

    /**
     * The translation key is not the option name. Zombies translates
     * through ZombieCount and ZombieLore.Speed through ZSpeed, so a
     * label built from the name would be missing.
     */
    public function testATranslationKeyThatDiffersFromTheNameIsUsed(): void
    {
        self::assertSame('ZombieCount', SandboxSchema::OPTIONS['Zombies']['translationKey']);
        self::assertSame('ZSpeed', SandboxSchema::OPTIONS['ZombieLore.Speed']['translationKey']);
        self::assertNotNull(SandboxSchema::OPTIONS['Zombies']['labels']['DE']);
        self::assertNotNull(SandboxSchema::OPTIONS['ZombieLore.Speed']['labels']['DE']);
    }

    /**
     * Options the game's own screen does not show still exist on every
     * server, so they are kept in their own group rather than dropped —
     * an editor that cannot see them would delete them on save.
     */
    public function testOptionsAbsentFromTheGamesScreenAreStillPresent(): void
    {
        foreach (['Farming', 'StartYear', 'NightLength', 'AlarmDecayModifier'] as $key) {
            self::assertArrayHasKey($key, SandboxSchema::OPTIONS, sprintf('"%s" was dropped', $key));
        }
    }

    /**
     * Provenance: without a build stamp, a game update that silently
     * moves a bound cannot be told from a generator bug.
     */
    public function testTheSchemaCarriesTheBuildItWasReadFrom(): void
    {
        self::assertNotNull(SandboxSchema::BUILD_ID, 'the schema has no provenance stamp');
        self::assertMatchesRegularExpression(
            '/^(?:B\d+\.\d+|\d+)$/',
            SandboxSchema::BUILD_ID,
            'expected a game version like B42.20, or a Steam buildid',
        );
    }

    /**
     * Two options are typed by a Java enum rather than a value count,
     * so their choices and default exist only in that class. Read as a
     * plain enum they arrive with no bounds at all, which the bounds
     * guard above caught.
     */
    public function testAnOptionTypedByAJavaEnumIsResolved(): void
    {
        $injury = SandboxSchema::OPTIONS['InjurySeverity'];

        self::assertSame('enum', $injury['type']);
        self::assertSame(3, $injury['numValues']);
        // NORMAL is the second of LOW, NORMAL, HIGH, and the game's own
        // default file agrees: `InjurySeverity = 2`.
        self::assertSame(2, $injury['default']);
        self::assertSame(['Low', 'Normal', 'High'], array_values($injury['choices']['EN']));

        $damage = SandboxSchema::OPTIONS['DamageToPlayerFromHitByACar'];

        self::assertSame(5, $damage['numValues']);
        // NONE is first, and `DamageToPlayerFromHitByACar = 1` in the
        // template confirms it.
        self::assertSame(1, $damage['default']);
    }

    /** @return array{sandbox: array<string, mixed>, ini: array<string, mixed>} */
    private static function fixture(): array
    {
        $raw = file_get_contents(self::FIXTURE);

        self::assertIsString($raw, 'the schema fixture is missing');

        $decoded = json_decode($raw, true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('sandbox', $decoded);
        self::assertArrayHasKey('ini', $decoded);

        return $decoded;
    }

    /**
     * JSON turns an empty PHP array into `[]` and integer keys into
     * strings, so the two sides are compared through the same shape.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function normalise(array $options): array
    {
        return json_decode((string) json_encode($options), true);
    }
}
