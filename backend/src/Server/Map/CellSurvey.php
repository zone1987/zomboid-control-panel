<?php

declare(strict_types=1);

namespace App\Server\Map;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * What each cell actually holds, per floor.
 *
 * A world is drawn one floor at a time, but almost no cell has more
 * than the ground: measured across six cells, floor 1 was 98-99.6 %
 * empty and floor 2 96.6-99.4 %. Drawing every floor over the whole
 * world therefore produces millions of blank tiles, uploads them as
 * empty objects, and pays for them.
 *
 * The lotheader names the floors a cell has, and the lotpack says which
 * of its 32x32 blocks carry anything on each of them. A block is 8x8
 * squares against a tile's 256, so block occupancy settles every tile
 * of a cell without drawing one.
 */
final readonly class CellSurvey
{
    /** Cells per renderer invocation; a process start costs 1.5-4 s. */
    private const CHUNK = 64;

    /** Reading headers is fast; parsing every lotpack is not. */
    private const TIMEOUT_SECONDS = 900;

    public function __construct(
        private LoggerInterface $logger,
        private ?string $rendererPath,
        private ?string $python,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->rendererPath !== null
            && $this->python !== null
            && is_file($this->rendererPath.'/main.py')
            && is_file($this->python);
    }

    /**
     * Reads the floor range of cells without touching their lotpacks.
     *
     * @param list<array{int, int}> $cells
     *
     * @return array<string, array{minlayer: int, maxlayer: int}>
     */
    public function floorRanges(string $directory, array $cells = []): array
    {
        /** @var array<string, array{minlayer: int, maxlayer: int}> $ranges */
        $ranges = [];

        foreach ($this->run($directory, $cells, headersOnly: true) as $key => $entry) {
            if (isset($entry['minlayer'], $entry['maxlayer'])) {
                $ranges[$key] = [
                    'minlayer' => (int) $entry['minlayer'],
                    'maxlayer' => (int) $entry['maxlayer'],
                ];
            }
        }

        return $ranges;
    }

    /**
     * Reads which blocks hold anything, per cell and floor.
     *
     * @param list<array{int, int}> $cells
     *
     * @return array<string, CellOccupancy>
     */
    public function occupancy(string $directory, array $cells = []): array
    {
        $occupancy = [];

        foreach ($this->run($directory, $cells, headersOnly: false) as $key => $entry) {
            if (isset($entry['error'])) {
                $this->logger->warning('Surveying a cell failed.', [
                    'cell' => $key,
                    'error' => $entry['error'],
                ]);

                continue;
            }

            $occupancy[$key] = CellOccupancy::fromSurvey($entry);
        }

        return $occupancy;
    }

    /**
     * @param list<array{int, int}> $cells
     *
     * @return array<string, array<string, mixed>>
     */
    private function run(string $directory, array $cells, bool $headersOnly): array
    {
        if (!$this->isAvailable() || !is_dir($directory)) {
            return [];
        }

        // Everything in the directory when no cells are named.
        if ($cells === []) {
            return $this->invoke($directory, [], $headersOnly);
        }

        $result = [];

        foreach (array_chunk($cells, self::CHUNK) as $chunk) {
            $result += $this->invoke($directory, $chunk, $headersOnly);
        }

        return $result;
    }

    /**
     * @param list<array{int, int}> $cells
     *
     * @return array<string, array<string, mixed>>
     */
    private function invoke(string $directory, array $cells, bool $headersOnly): array
    {
        $command = [(string) $this->python, 'main.py', 'survey', $directory];

        foreach ($cells as $cell) {
            $command[] = sprintf('%d,%d', $cell[0], $cell[1]);
        }

        if ($headersOnly) {
            $command[] = '--headers-only';
        }

        $process = new Process($command, $this->rendererPath, timeout: self::TIMEOUT_SECONDS);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->logger->warning('The survey command failed.', [
                'error' => mb_substr($process->getErrorOutput(), 0, 2000),
            ]);

            return [];
        }

        try {
            $decoded = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logger->warning('The survey returned something that is not JSON.', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
