<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Map;

use App\Server\Map\CellFetcher;
use App\Server\Map\IsometricTiles;
use App\Server\Map\RenderRoot;
use App\Server\Map\Textures\TexturePackStore;
use App\Server\Map\TileRenderer;
use App\Server\Storage\FileBrowserInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The configuration handed to pzmap2dzi decides what a run costs.
 *
 * It is written by rewriting the template with regular expressions, so
 * a key that fails to match is silent: the template's own value stands
 * and the run draws something nobody asked for. omit_levels went
 * unwritten this way, and every render drew the deepest pyramid level
 * -- three quarters of the output, and days of upload.
 */
final class TileRendererConfigurationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/renderer-'.bin2hex(random_bytes(6));

        mkdir($this->root.'/conf', 0o775, true);
        copy(
            \dirname(__DIR__, 4).'/../renderer/conf/conf.yaml',
            $this->root.'/conf/conf.yaml',
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/conf/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->root.'/conf');
        @rmdir($this->root);
    }

    public function testWritesTheOmitLevelsTheRendererWasGiven(): void
    {
        $body = $this->configurationFor(omitLevels: 2);

        self::assertMatchesRegularExpression('/^    omit_levels: 2$/m', $body);
        self::assertDoesNotMatchRegularExpression('/^    omit_levels: 0$/m', $body);
    }

    /** Full resolution stays reachable, for an operator with the room. */
    public function testWritesFullResolutionWhenAskedFor(): void
    {
        self::assertMatchesRegularExpression(
            '/^    omit_levels: 0$/m',
            $this->configurationFor(omitLevels: 0),
        );
    }

    /**
     * The commented examples in the template must not be rewritten.
     *
     * They carry an omit_levels[default](base) form that would win over
     * the plain key if it were ever uncommented.
     */
    public function testLeavesTheCommentedExamplesAlone(): void
    {
        $body = $this->configurationFor(omitLevels: 2);

        self::assertStringContainsString('#     omit_levels[default](base): 2', $body);
        self::assertSame(1, preg_match_all('/^    omit_levels: /m', $body));
    }

    /**
     * The image must cover the whole world whatever a batch draws.
     *
     * Left to itself pzmap2dzi sizes the pyramid to the cells in hand,
     * so every batch would write a different origin and tiles uploaded
     * before it would land in the wrong place.
     */
    public function testStillPinsTheImageToTheWholeWorld(): void
    {
        $body = $this->configurationFor(omitLevels: 2);

        self::assertStringContainsString("    dzi_cell_range:\n        - [0, 18, 45, 45]", $body);
        self::assertDoesNotMatchRegularExpression('/^    dzi_cell_range\[default\]: /m', $body);
    }

    public function testStillWritesTheCellRangeAndFloors(): void
    {
        $body = $this->configurationFor(omitLevels: 2, layers: [0, 1]);

        self::assertStringContainsString("    render_cell_range:\n        - [47, 27]", $body);
        self::assertMatchesRegularExpression('/^    layer_range: \[0, 1\]$/m', $body);
    }

    /** @param array{int, int}|null $layers */
    private function configurationFor(int $omitLevels, ?array $layers = null): string
    {
        // Real collaborators rather than doubles: they are final, and
        // writing a configuration touches none of them.
        $cells = new CellFetcher(
            $this->createMock(FileBrowserInterface::class),
            $this->root.'/cells',
        );
        $textures = new TexturePackStore($this->root.'/textures');

        $renderer = new TileRenderer(
            $cells,
            new RenderRoot($this->root.'/pz-root', $cells, $textures),
            $textures,
            new IsometricTiles($this->root.'/html/map_data/base'),
            new NullLogger(),
            $this->root,
            '/usr/bin/python3',
            $this->root,
            $omitLevels,
        );

        $write = new \ReflectionMethod($renderer, 'writeConfiguration');
        $path = $write->invoke($renderer, [[47, 27]], $layers);

        self::assertIsString($path);

        return (string) file_get_contents($path);
    }
}
