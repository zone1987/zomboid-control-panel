<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Server\Vehicles\Models\VehicleCatalogue;
use App\Server\Vehicles\SpawnableVehicles;
use PHPUnit\Framework\TestCase;

/**
 * The numbers in llms.txt, checked against what they describe.
 *
 * A document nobody verifies becomes a document nobody trusts, and these
 * particular figures are the ones a reader would take on faith.
 */
final class DocumentationTest extends TestCase
{
    private const LLMS = __DIR__.'/../../../llms.txt';

    public function testDescribesTheVehicleCountItActuallyHas(): void
    {
        $vehicles = \count(VehicleCatalogue::scripts());

        self::assertStringContainsString(
            sprintf('all %d base-game vehicles', $vehicles),
            self::text(),
            'llms.txt names a different number of vehicles than the catalogue holds',
        );
    }

    public function testDescribesTheBodyCountTheGroupingProduces(): void
    {
        $bodyOf = new \ReflectionMethod(SpawnableVehicles::class, 'bodyOf');
        $bodies = [];

        foreach (VehicleCatalogue::scripts() as $script) {
            $bodies[(string) $bodyOf->invoke(null, 'Base.'.$script, VehicleCatalogue::find($script))] = true;
        }

        self::assertStringContainsString(
            sprintf('grouped into %d body shells', \count($bodies)),
            self::text(),
            'llms.txt names a different number of bodies than the grouping produces',
        );
    }

    public function testNamesTheBridgeVersionThatShips(): void
    {
        $lua = (string) file_get_contents(__DIR__.'/../../resources/bridge/ZomboidControlBridge.lua');

        self::assertSame(
            1,
            preg_match('/BRIDGE_VERSION = "([0-9.]+)"/', $lua, $found),
            'the bridge no longer declares a version in the expected shape',
        );

        self::assertStringContainsString(
            sprintf('currently %s', $found[1]),
            self::text(),
            'llms.txt names a different bridge version than the one that ships',
        );
    }

    /** The art must never be committed; the document says so, so it must hold. */
    public function testTheAssetDirectoryIsIgnored(): void
    {
        $ignore = (string) file_get_contents(__DIR__.'/../../.gitignore');

        self::assertStringContainsString('/var/', $ignore);
    }

    private static function text(): string
    {
        $text = file_get_contents(self::LLMS);

        self::assertIsString($text, 'llms.txt is missing');

        return $text;
    }
}
