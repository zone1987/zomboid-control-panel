<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Bridge;

use PHPUnit\Framework\TestCase;

/**
 * Every climate method the bridge calls has to exist on its class.
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
     * Lua has no types, so the pairing is a convention the bridge keeps:
     * `climate` is always the manager, `float` always a ClimateFloat,
     * `isSnow` always the one ClimateBool.
     */
    private const HOLDS = [
        'climate' => 'ClimateManager',
        'float' => 'ClimateFloat',
        'isSnow' => 'ClimateBool',
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
