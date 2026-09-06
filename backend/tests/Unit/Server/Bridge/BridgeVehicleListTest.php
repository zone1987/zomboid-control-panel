<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Bridge;

use PHPUnit\Framework\TestCase;

/**
 * How the bridge chooses its vehicle list is exercised in Lua, because
 * Lua is what runs on the game server -- see
 * tests/Bridge/vehicles-test.lua. That test carries a copy of the two
 * functions, and a copy can drift.
 *
 * This checks it has not.
 */
final class BridgeVehicleListTest extends TestCase
{
    private const BRIDGE = __DIR__.'/../../../../resources/bridge/ZomboidControlBridge.lua';
    private const LUA_TEST = __DIR__.'/../../../Bridge/vehicles-test.lua';

    /** @return list<array{string}> */
    public static function copiedFunctions(): array
    {
        return [['local function indexable(list)'], ['local function vehicleList()']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('copiedFunctions')]
    public function testTheCopyInTheLuaTestMatchesTheBridge(string $signature): void
    {
        self::assertSame(
            $this->functionIn(self::BRIDGE, $signature),
            $this->functionIn(self::LUA_TEST, $signature),
            sprintf(
                '"%s" in tests/Bridge/vehicles-test.lua no longer matches the bridge. '
                .'Copy it across again, then run the Lua test.',
                $signature,
            ),
        );
    }

    /**
     * The cell comes first because that is what the game's own code
     * does -- client/Vehicles/ISUI/ISVehicleBloodUI.lua iterates
     * getCell():getVehicles() with size() and get(i). VehicleManager
     * appears in no shipped Lua file, so it is a fallback rather than
     * the first choice.
     */
    public function testTheBridgeAsksTheCellFirstAndKeepsFallbacks(): void
    {
        $chooser = $this->functionIn(self::BRIDGE, 'local function vehicleList()');
        $cell = strpos($chooser, 'getCell()');
        $manager = strpos($chooser, 'VehicleManager.instance');

        self::assertNotFalse($cell, 'The bridge no longer reads the cell at all.');
        self::assertNotFalse($manager, 'The bridge lost its VehicleManager fallback.');
        self::assertLessThan($manager, $cell, 'The manager is asked before the cell.');
    }

    /** An empty list and an unreadable one must not look the same. */
    public function testTheBridgeReportsWhichSourceAnswered(): void
    {
        $chooser = $this->functionIn(self::BRIDGE, 'local function vehicleList()');

        self::assertStringContainsString('tried', $chooser);
        self::assertStringContainsString('"none"', $chooser);
    }

    /**
     * Answering size() is the test, and element zero is deliberately
     * not probed: an empty list has none, so probing it threw and the
     * source was discarded. With nobody near a vehicle every source
     * looked broken and the panel was told "none" rather than
     * "none loaded" -- seen live on the server.
     */
    public function testAListIsAcceptedOnSizeAloneWithoutProbingAnElement(): void
    {
        $guard = $this->functionIn(self::BRIDGE, 'local function indexable(list)');

        self::assertStringContainsString(':size()', $guard);
        self::assertStringNotContainsString(':get(0)', $guard);
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
