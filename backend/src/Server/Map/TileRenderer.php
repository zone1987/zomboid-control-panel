<?php

declare(strict_types=1);

namespace App\Server\Map;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Renders map cells on demand.
 *
 * The deepest zoom level is three quarters of an isometric render --
 * 336 GB of the 438 a full world costs. Holding it is what makes the
 * map unhostable; producing it takes about a second a cell, which
 * nobody notices.
 *
 * So the panel ships the levels above and renders the deepest one when
 * somebody actually looks that closely. A server whose players never
 * leave Muldraugh never renders Louisville.
 */
final readonly class TileRenderer
{
    /** Long enough for a slow disk, short enough not to hang a request. */
    private const TIMEOUT_SECONDS = 90;

    public function __construct(
        private IsometricTiles $tiles,
        private LoggerInterface $logger,
        private ?string $rendererPath,
        private ?string $gamePath,
    ) {
    }

    /**
     * Whether this installation can render at all.
     *
     * It needs pzmap2dzi and a full game installation: the renderer
     * reads the client texture packs, which a dedicated server does not
     * ship. Most operators will not have both, and the interface says
     * so rather than offering a button that cannot work.
     */
    public function isAvailable(): bool
    {
        return $this->rendererPath !== null
            && $this->gamePath !== null
            && is_file($this->rendererPath.'/main.py')
            && is_dir($this->gamePath.'/media/texturepacks');
    }

    /**
     * Renders the cells behind one tile, if they are not there already.
     *
     * @param list<array{int, int}> $cells
     *
     * @return bool whether anything was produced
     */
    public function render(array $cells): bool
    {
        if (!$this->isAvailable() || $cells === []) {
            return false;
        }

        $configuration = $this->writeConfiguration($cells);

        if ($configuration === null) {
            return false;
        }

        try {
            $process = new Process(
                ['python3', 'main.py', '-c', $configuration, 'render', 'base'],
                $this->rendererPath,
                timeout: self::TIMEOUT_SECONDS,
            );

            $process->run();

            if (!$process->isSuccessful()) {
                $this->logger->warning('Rendering map cells failed.', [
                    'cells' => $cells,
                    'error' => $process->getErrorOutput(),
                ]);

                return false;
            }

            return true;
        } catch (ProcessTimedOutException) {
            $this->logger->warning('Rendering map cells timed out.', ['cells' => $cells]);

            return false;
        } finally {
            @unlink($configuration);
        }
    }

    /**
     * A configuration naming just these cells.
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

        $range = implode("\n", array_map(
            static fn (array $cell): string => sprintf('        - [%d, %d]', $cell[0], $cell[1]),
            $cells,
        ));

        $body = preg_replace(
            '/^    render_cell_range:.*$/m',
            "    render_cell_range:\n".$range,
            $body,
        ) ?? $body;

        $path = sys_get_temp_dir().'/'.uniqid('pz-render-', true).'.yaml';

        return file_put_contents($path, $body) === false ? null : $path;
    }
}
