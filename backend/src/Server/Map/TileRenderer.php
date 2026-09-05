<?php

declare(strict_types=1);

namespace App\Server\Map;

use App\Entity\GameServer;
use App\Server\Map\Textures\TexturePackStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Renders map cells on demand.
 *
 * Nothing here needs a game installation, which is the point: the
 * geometry comes off the game server a cell at a time, the artwork was
 * uploaded once, and pzmap2dzi is in the image. A panel on a rented
 * server can therefore draw the isometric map without anyone copying
 * 4.2 GB anywhere.
 *
 * The deepest zoom level is three quarters of a full render. Producing
 * a cell takes about a second, which nobody notices; holding every
 * cell is what makes the map unhostable.
 */
final readonly class TileRenderer
{
    /** Long enough for a slow disk, short enough not to hang a request. */
    private const TIMEOUT_SECONDS = 90;

    public function __construct(
        private CellFetcher $cells,
        private RenderRoot $root,
        private TexturePackStore $textures,
        private IsometricTiles $tiles,
        private LoggerInterface $logger,
        private ?string $rendererPath,
        private ?string $python,
        private string $projectDirectory,
    ) {
    }

    /**
     * Whether this installation can render at all.
     *
     * The interface asks this before offering the isometric view, so
     * the answer has to name what is missing rather than only refuse.
     *
     * @return array{ready: bool, renderer: bool, textures: bool, missingPacks: list<string>}
     */
    public function readiness(): array
    {
        $renderer = $this->rendererPath !== null
            && $this->python !== null
            && is_file($this->rendererPath.'/main.py')
            && is_file($this->python);

        return [
            'ready' => $renderer && $this->textures->isComplete(),
            'renderer' => $renderer,
            'textures' => $this->textures->isComplete(),
            'missingPacks' => $this->textures->missing(),
        ];
    }

    public function isAvailable(): bool
    {
        return $this->readiness()['ready'];
    }

    /**
     * Renders the cells behind one tile.
     *
     * Cells that are not here yet are fetched from the game server
     * first; one that the server does not have is silently skipped,
     * because the world is not a rectangle and the tile may simply
     * overlap its edge.
     *
     * @param list<array{int, int}> $cells
     *
     * @return bool whether anything was produced
     */
    public function render(GameServer $server, array $cells): bool
    {
        if (!$this->isAvailable() || $cells === []) {
            return false;
        }

        $available = [];

        foreach ($cells as $cell) {
            if ($this->cells->fetch($server, $cell[0], $cell[1])) {
                $available[] = $cell;
            }
        }

        if ($available === []) {
            return false;
        }

        $this->root->ensure();

        $configuration = $this->writeConfiguration($available);

        if ($configuration === null) {
            return false;
        }

        try {
            $process = new Process(
                [(string) $this->python, 'main.py', '-c', $configuration, 'render', 'base'],
                $this->rendererPath,
                timeout: self::TIMEOUT_SECONDS,
            );

            $process->run();

            if (!$process->isSuccessful()) {
                $this->logger->warning('Rendering map cells failed.', [
                    'cells' => $available,
                    'error' => mb_substr($process->getErrorOutput(), 0, 2000),
                    'output' => mb_substr($process->getOutput(), 0, 2000),
                ]);

                return false;
            }

            return true;
        } catch (ProcessTimedOutException) {
            $this->logger->warning('Rendering map cells timed out.', ['cells' => $available]);

            return false;
        } finally {
            @unlink($configuration);
        }
    }

    /**
     * Unpacks the texture packs into what the renderer reads.
     *
     * A one-off after an upload: pzmap2dzi wants the sprites as files,
     * and cutting 414 MB of packs apart takes long enough that no
     * request should wait for it.
     */
    public function unpackTextures(): bool
    {
        if (!$this->readiness()['renderer'] || !$this->textures->isComplete()) {
            return false;
        }

        $this->root->ensure();

        $configuration = $this->writeConfiguration([]);

        if ($configuration === null) {
            return false;
        }

        try {
            $process = new Process(
                [(string) $this->python, 'main.py', '-c', $configuration, 'unpack'],
                $this->rendererPath,
                // Unpacking is minutes, not seconds; it runs from a
                // worker rather than a request.
                timeout: 1800,
            );

            $process->run();

            if (!$process->isSuccessful()) {
                $this->logger->warning('Unpacking texture packs failed.', [
                    'error' => mb_substr($process->getErrorOutput(), 0, 2000),
                ]);
            }

            return $process->isSuccessful();
        } finally {
            @unlink($configuration);
        }
    }

    /** Where the finished tiles land. */
    public function tilesDirectory(): string
    {
        return $this->tiles->directory();
    }

    /**
     * Whether the textures have been cut apart yet.
     *
     * Without this step every tile comes out empty and the log says
     * only "Missing texture", which is easy to chase in the wrong
     * direction.
     */
    public function texturesUnpacked(): bool
    {
        // Written under output_root, beside the tiles themselves.
        return is_dir(\dirname($this->tiles->directory(), 3).'/texture');
    }

    /**
     * A configuration pointing at this installation's own directories.
     *
     * Written per request rather than kept: two viewers looking at
     * different places would otherwise overwrite each other's cell
     * range between writing it and reading it.
     *
     * @param list<array{int, int}> $cells
     */
    private function writeConfiguration(array $cells): ?string
    {
        $template = $this->rendererPath.'/conf/conf.yaml';

        if (!is_file($template)) {
            return null;
        }

        $body = (string) file_get_contents($template);

        // The keys carry a trailing comment after the block marker, so
        // the value is the indented line beneath rather than the rest
        // of the same one.
        $replacements = [
            // pz_root is where a game installation would be. Here it is
            // a directory holding only what was actually needed: the
            // uploaded packs and the cells fetched so far.
            'pz_root' => $this->projectDirectory.'/var/pz-root',
            // pzmap2dzi appends html/map_data/base of its own accord,
            // so output_root is two levels above where tiles land.
            'output_root' => \dirname($this->tiles->directory(), 3),
        ];

        foreach ($replacements as $key => $value) {
            $body = preg_replace(
                sprintf('/^(%s:[^\n]*\n)[ \t]+\S[^\n]*$/m', preg_quote($key, '/')),
                '$1    '.str_replace('$', '\$', $value),
                $body,
                1,
            ) ?? $body;
        }

        if ($cells !== []) {
            $range = implode("\n", array_map(
                static fn (array $cell): string => sprintf('        - [%d, %d]', $cell[0], $cell[1]),
                $cells,
            ));

            $body = preg_replace(
                '/^    render_cell_range:.*$/m',
                "    render_cell_range:\n".$range,
                $body,
            ) ?? $body;
        }

        // Beside the template, not in a temporary directory: main.py
        // resolves map_conf_default and the rest relative to the
        // configuration file it was handed.
        $path = $this->rendererPath.'/conf/'.uniqid('render-', true).'.yaml';

        return file_put_contents($path, $body) === false ? null : $path;
    }
}
