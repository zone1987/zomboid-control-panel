<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Bridge;

use PHPUnit\Framework\TestCase;

/**
 * How the bridge describes a spawnable vehicle is exercised in Lua,
 * because Lua is what runs on the game server -- see
 * tests/Bridge/vehicle-catalogue-test.lua. That test carries a copy of
 * the function, and a copy can drift.
 *
 * This checks it has not.
 */
final class BridgeVehicleCatalogueTest extends TestCase
{
    private const BRIDGE = __DIR__.'/../../../../resources/bridge/ZomboidControlBridge.lua';
    private const LUA_TEST = __DIR__.'/../../../Bridge/vehicle-catalogue-test.lua';
    private const SIGNATURE = 'local function describeVehicleScript(name, script)';

    public function testTheCopyInTheLuaTestMatchesTheBridge(): void
    {
        self::assertSame(
            $this->functionIn(self::BRIDGE, self::SIGNATURE),
            $this->functionIn(self::LUA_TEST, self::SIGNATURE),
            'describeVehicleScript in tests/Bridge/vehicle-catalogue-test.lua no longer '
            .'matches the bridge. Copy it across again, then run the Lua test.',
        );
    }

    /**
     * A server runs mods, so the list of what can be spawned has to come
     * from the server. Shipping a table with the panel would miss exactly
     * the vehicles an operator most wants.
     */
    public function testTheCatalogueIsReadFromTheServerRatherThanADeclaredList(): void
    {
        $writer = $this->functionIn(
            self::BRIDGE,
            'local function writeVehicleCatalogue()',
        );

        self::assertStringContainsString('getAllVehicles', $writer);
        self::assertStringContainsString('getScriptManager', $writer);
    }

    /**
     * A vehicle the script manager will not describe is still spawnable
     * by name, so hiding it would remove a working capability.
     */
    public function testAnUndescribableVehicleStaysInTheList(): void
    {
        $writer = $this->functionIn(
            self::BRIDGE,
            'local function writeVehicleCatalogue()',
        );

        // The fallback entry, carrying the name and nothing else.
        self::assertStringContainsString('{\\"script\\":\\"%s\\"}', $writer);
    }

    /** The liveries come from the script, never from the name. */
    public function testTheLiveriesAreReadFromTheScript(): void
    {
        $describe = $this->functionIn(self::BRIDGE, self::SIGNATURE);

        self::assertStringContainsString('getSkinCount', $describe);
        self::assertStringContainsString('getSkin', $describe);
        self::assertStringContainsString('textureMask', $describe);
        // getModel() returns an object, so the file name has to be read
        // off it: printing the object gave a Java identity hash.
        self::assertStringContainsString('getFile', $describe);
    }

    private function functionIn(string $path, string $signature): string
    {
        $source = (string) file_get_contents($path);
        $start = strpos($source, $signature);

        self::assertNotFalse(
            $start,
            sprintf('"%s" is not in %s.', $signature, basename($path)),
        );

        // Ends at the first line that starts a new top-level definition.
        $rest = substr($source, $start);
        $end = strpos($rest, "\nlocal function ", 1);
        $comment = strpos($rest, "\n---", 1);

        if ($comment !== false && ($end === false || $comment < $end)) {
            $end = $comment;
        }

        return trim($end === false ? $rest : substr($rest, 0, $end));
    }
}
