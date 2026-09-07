<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Bridge;

use App\Server\Bridge\BridgeCommand;
use PHPUnit\Framework\TestCase;

/**
 * Every game method the bridge calls has to exist on its class.
 *
 * Lua finds out at run time and on the live server: `readClimate` called
 * `getFinalValue()` on a `ClimateBool`, which only `ClimateFloat` has,
 * and the whole handler answered "Object tried to call nil" after an
 * upload and a restart. Nothing in the panel could have known.
 *
 * The fixture is the real API, taken from build 42 with
 * `javap -p zombie/iso/weather/ClimateManager*.class`, so this test needs
 * no game installation. Regenerate it when the game's build changes; a
 * method that disappears there should fail here rather than in a server
 * log nobody is reading.
 */
final class BridgeClimateCallsTest extends TestCase
{
    private const LUA = __DIR__.'/../../../../resources/bridge/ZomboidControlBridge.lua';
    private const API = __DIR__.'/../../../Fixtures/climate-api.json';

    /**
     * The bridge's variable names and the class each one holds.
     *
     * Lua has no types, so the pairing is a convention the bridge keeps
     * and this test enforces: `climate` is always the manager, `float` a
     * ClimateFloat, `isSnow` the one ClimateBool, `colour` a
     * ClimateColor, `rgb` a zombie.core.Color. Reusing a name for a
     * different class is what makes a call unverifiable, so the
     * convention is part of the guard rather than a style preference.
     *
     * `option` is deliberately absent: the sandbox options are reached
     * by name through `getOptionByName`/`set` rather than through their
     * public fields, because indexing one of those fields from Lua
     * returns null — the live server answered "attempted index:
     * getValueAsObject of non-table: null" before this was corrected.
     */
    private const HOLDS = [
        'climate' => 'ClimateManager',
        'float' => 'ClimateFloat',
        'isSnow' => 'ClimateBool',
        'colour' => 'ClimateColor',
        'storm' => 'ThunderStorm',
        'sandbox' => 'SandboxOptions',
        'rgb' => 'Color',
        'player' => 'IsoPlayer',
        'damage' => 'BodyDamage',
        'part' => 'BodyPart',
        'stats' => 'Stats',
        'stat' => 'CharacterStat',
        'nutrition' => 'Nutrition',
        'fitness' => 'Fitness',
        'descriptor' => 'SurvivorDesc',
    ];

    public function testEveryCallExistsOnItsClass(): void
    {
        $lua = self::lua();
        $api = self::api();

        foreach (self::HOLDS as $variable => $class) {
            preg_match_all(sprintf('/\b%s:(\w+)\(/', $variable), $lua, $found);

            self::assertNotEmpty(
                $found[1],
                sprintf('the bridge no longer calls anything on "%s"', $variable),
            );

            foreach (array_unique($found[1]) as $method) {
                self::assertContains(
                    $method,
                    $api[$class],
                    sprintf('%s has no method %s(), called as %s:%s()', $class, $method, $variable, $method),
                );
            }
        }
    }

    /**
     * `finalValue` is protected on ClimateBool with only a setter, so the
     * manager's own getter is the only way to read it. This is the exact
     * mistake that reached a live server.
     */
    public function testTheSnowFlagIsReadThroughTheManager(): void
    {
        $api = self::api();

        self::assertNotContains('getFinalValue', $api['ClimateBool']);
        self::assertContains('getPrecipitationIsSnow', $api['ClimateManager']);

        self::assertStringNotContainsString('isSnow:getFinalValue()', self::lua());
    }

    /**
     * Colour has getR/getG/getB but **no getA** — only getAlphaFloat.
     * The obvious fourth name is the one that would fail on a live
     * server, exactly as ClimateBool::getFinalValue did.
     */
    public function testTheAlphaChannelIsReadByItsRealName(): void
    {
        $api = self::api();

        self::assertContains('getR', $api['Color']);
        self::assertNotContains('getA', $api['Color']);
        self::assertContains('getAlphaFloat', $api['Color']);

        self::assertStringNotContainsString(':getA()', self::lua());
    }

    /**
     * The statistic ids the bridge asks for must be the ones the game
     * registers. They come from CharacterStat's own constant pool, not
     * from its constant *names* — and `ORDERED_STATS` cannot be walked
     * from Lua at all, being a Java array rather than a list, so the
     * names are the only route.
     */
    public function testEveryStatisticIsAskedForByItsRealId(): void
    {
        $api = self::api();
        $lua = self::lua();

        self::assertCount(24, $api['_characterStatIds']);

        preg_match(
            '/local CHARACTER_STATS = \{(.*?)\}/s',
            $lua,
            $found,
        );

        self::assertArrayHasKey(1, $found, 'the bridge no longer names the statistics');

        preg_match_all('/"(\w+)"/', $found[1], $names);

        self::assertSame(
            $api['_characterStatIds'],
            $names[1],
            'the bridge asks for statistics the game does not register',
        );

        // A Java array has neither, so either would fail at run time.
        self::assertStringNotContainsString('ORDERED_STATS:size()', $lua);
        self::assertStringNotContainsString('ORDERED_STATS:get(', $lua);

        // And the panel's own list has to be the same one, or it would
        // accept a name the bridge then cannot look up.
        self::assertSame($api['_characterStatIds'], BridgeCommand::CHARACTER_STATS);
    }


    /**
     * The sandbox options are reached by method, never by field.
     *
     * `elecShutModifier` is `public` on `SandboxOptions`, and indexing a
     * Java field from Lua returns null — the live server answered
     * "attempted index: getValueAsObject of non-table". It took two
     * uploads to fix, because a scripted edit left one call site on the
     * old shape while the other moved.
     */
    public function testTheSandboxOptionsAreReadByMethod(): void
    {
        $lua = self::lua();

        foreach (['elecShutModifier', 'waterShutModifier'] as $field) {
            self::assertStringNotContainsString(
                '.'.$field,
                $lua,
                sprintf('%s is a Java field and cannot be indexed from Lua', $field),
            );
        }

        // The methods that work, so this fails if the reads vanish too.
        self::assertStringContainsString('getElecShutModifier', $lua);
        self::assertStringContainsString('getWaterShutModifier', $lua);
    }

    /**
     * The perk table is walked by method, never through PerkInfo.
     *
     * `PerkInfo.perk` is a public field with **no getter at all**, so
     * `info.perk` read nil from Lua, the level-0 guard was never
     * reached, and every player's skills arrived as `{}` — the panel
     * showed an empty list and reported no error. The route that works
     * is the one the game's own ISPerkLog takes.
     */
    public function testTheSkillsAreReadThroughThePerkTable(): void
    {
        $api = self::api();
        $lua = self::lua();

        // The field with no getter, which is why it cannot be used.
        self::assertContains('perk', $api['_fieldsPerkInfo']);
        self::assertNotContains('getPerk', $api['PerkInfo']);

        // testNoPublicJavaFieldIsIndexed covers `info.perk` itself;
        // here it is the list that route needs which must stay gone.
        self::assertStringNotContainsString('getPerkList', $lua);

        foreach (['getMaxIndex', 'fromIndex'] as $method) {
            self::assertContains($method, $api['Perks']);
            self::assertStringContainsString('Perks.'.$method, $lua);
        }

        self::assertContains('getPerk', $api['PerkFactory']);
        self::assertStringContainsString('PerkFactory.getPerk', $lua);

        foreach (['getId', 'getParent'] as $method) {
            self::assertContains($method, $api['Perk']);
        }

        self::assertStringContainsString('getPerkLevel', $lua);
    }

    /**
     * No public Java field is indexed on a variable holding a game object.
     *
     * Five separate uploads shipped this same mistake in five places, so
     * it is checked against the classes' real fields rather than the
     * handful already known. Scoped to the variables in HOLDS plus the
     * perk locals: a bare `.name` is usually a Lua table key, and a
     * guard that cannot tell those apart gets switched off. A field
     * reads nil from Lua, and nil does not throw — it produces an empty
     * result the panel then reports as success.
     */
    public function testNoPublicJavaFieldIsIndexed(): void
    {
        $api = self::api();
        $lua = self::stripComments(self::lua());

        // The perk locals are not in HOLDS: they are read inside one
        // function rather than being a bridge-wide convention.
        $holders = [...array_keys(self::HOLDS), 'perk', 'parent', 'info'];

        $checked = 0;

        foreach ($api as $key => $members) {
            if (!str_starts_with($key, '_fields')) {
                continue;
            }

            foreach ($members as $field) {
                foreach ($holders as $holder) {
                    ++$checked;

                    self::assertStringNotContainsString(
                        $holder.'.'.$field,
                        $lua,
                        sprintf(
                            '%s is a public Java field on %s and reads nil from Lua; call its getter instead',
                            $field,
                            substr($key, 7),
                        ),
                    );
                }
            }
        }

        self::assertGreaterThan(100, $checked, 'the fixture no longer lists any public fields');
    }

    /** Comments name the traps deliberately, so they are not evidence. */
    private static function stripComments(string $lua): string
    {
        return preg_replace('/^\s*--.*$/m', '', $lua) ?? $lua;
    }

    /** @return array<string, list<string>> */
    private static function api(): array
    {
        $raw = file_get_contents(self::API);

        self::assertIsString($raw, 'the climate API fixture is missing');

        $api = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);

        self::assertIsArray($api);

        foreach (array_values(self::HOLDS) as $class) {
            self::assertArrayHasKey($class, $api, $class);
        }

        self::assertArrayHasKey('_characterStatIds', $api);

        /** @var array<string, list<string>> $api */
        return $api;
    }

    private static function lua(): string
    {
        $contents = file_get_contents(self::LUA);

        self::assertIsString($contents, 'the bridge is missing');

        return $contents;
    }
}
