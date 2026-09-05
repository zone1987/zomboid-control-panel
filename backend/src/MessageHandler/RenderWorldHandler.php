<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RenderWorld;
use App\Repository\GameServerRepository;
use App\Server\Map\CellFetcher;
use App\Server\Map\RenderProgress;
use App\Server\Map\TileRenderer;
use App\Server\Map\TileUploader;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Renders the world, cell by cell, and streams the tiles out.
 *
 * Hours of work, so it reports as it goes and resumes where it
 * stopped: cells already in the store are skipped, and nothing is
 * deleted locally until the store confirms it has it.
 */
#[AsMessageHandler]
final readonly class RenderWorldHandler
{
    /** Enough to keep every core busy, small enough to report often. */
    private const BATCH = 6;

    /** Extra passes over tiles the verify step found missing. */
    private const RETRY_ROUNDS = 4;

    /** Cells to get through before the run's own occupancy means anything. */
    private const SAMPLE_CELLS = 600;

    /** 4065 of 4992 in build 42's default range, from its map_info.json. */
    private const OCCUPIED_SHARE = 0.814;

    /**
     * Floors in the order they are drawn.
     *
     * Ground first, over the whole world, because that is the floor
     * somebody looking at the map sees -- waiting hours for a map that
     * exists only in one corner is the wrong way round. Then outwards
     * from it: the first storey and the basement are where most
     * interiors are, and the far ends of the range are nearly empty.
     */
    private const FLOOR_ORDER = [0, 1, -1, 2, 3];

    /** The world is 78 by 64 cells; most of the corners are empty. */
    private const COLUMNS = 78;
    private const ROWS = 64;

    public function __construct(
        private GameServerRepository $servers,
        private TileRenderer $renderer,
        private CellFetcher $cells,
        private TileUploader $uploader,
        private RenderProgress $progress,
        private \App\Server\Map\CellLedger $ledger,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RenderWorld $message): void
    {
        $server = $this->servers->find($message->serverId);

        if ($server === null || !$this->renderer->isAvailable()) {
            $this->progress->write([
                'state' => RenderProgress::FAILED,
                'error' => 'map.rendererNotReady',
            ]);

            return;
        }

        $tiles = $this->renderer->tilesDirectory();
        $started = time();

        // Every key this run wrote. A world is 1.5 million tiles, so
        // this is the one large thing held in memory -- about 90 MB of
        // strings, against re-listing the bucket for every cell.
        $written = [];

        $counts = [
            'state' => RenderProgress::RUNNING,
            'startedAt' => $started,
            'cellsTotal' => self::COLUMNS * self::ROWS * \count(self::FLOOR_ORDER),
            'floorsTotal' => \count(self::FLOOR_ORDER),
            'currentFloor' => self::FLOOR_ORDER[0],
            'cellsDone' => 0,
            'cellsRendered' => 0,
            'cellsEmpty' => 0,
            'cellsSkipped' => 0,
            'tilesUploaded' => 0,
            'tilesSkipped' => 0,
            'tilesEstimated' => 0,
            'tilesProduced' => 0,
            'tilesFailed' => 0,
            'bytesUploaded' => 0,
            'bytesHeld' => 0,
            'objectsHeld' => 0,
            'phase' => 'starting',
            // So the panel can end this run outright rather than asking
            // it to notice: an upload that keeps going costs money.
            'workerPid' => getmypid(),
            'currentCell' => '',
            'currentTile' => '',
        ];

        $this->progress->clearStop();
        $this->progress->resume();
        $this->progress->write($counts);

        foreach ($this->passes() as [$floor, $batch]) {
            // Checked between batches, where the last upload has been
            // verified and nothing is half-written.
            if ($this->progress->stopRequested()) {
                $this->progress->clearStop();
                $counts['state'] = RenderProgress::STOPPED;
                $counts['finishedAt'] = time();
                $this->progress->write($counts);

                return;
            }

            while ($this->progress->isPaused() && !$this->progress->stopRequested()) {
                if (($counts['phase'] ?? '') !== 'paused') {
                    $counts['phase'] = 'paused';
                    $this->progress->write($counts);
                }

                sleep(2);
            }

            if ($this->progress->stopRequested()) {
                $this->progress->clearStop();
                $this->progress->resume();
                $counts['state'] = RenderProgress::STOPPED;
                $counts['finishedAt'] = time();
                $this->progress->write($counts);

                return;
            }

            $wanted = [];
            $checksums = [];

            foreach ($batch as $cell) {
                if (!$this->cells->fetch($server, $cell[0], $cell[1])) {
                    ++$counts['cellsEmpty'];

                    continue;
                }

                $checksum = $this->cells->checksumFor($cell[0], $cell[1]);

                // Same bytes, same picture: the tiles in the store are
                // already this cell, so there is nothing to draw.
                if ($checksum !== null && $this->ledger->matches($cell[0], $cell[1], $checksum, $floor)) {
                    ++$counts['cellsSkipped'];

                    continue;
                }

                $wanted[] = $cell;

                if ($checksum !== null) {
                    $checksums[\App\Server\Map\CellLedger::name($cell[0], $cell[1], $floor)] = $checksum;
                }
            }

            $counts['currentCell'] = $batch[0][0].','.$batch[0][1];
            $counts['currentFloor'] = $floor;
            $counts['phase'] = 'rendering';
            $this->progress->write($counts);

            if ($wanted !== [] && $this->renderer->render($server, $wanted, [$floor, $floor + 1])) {
                $counts['phase'] = 'uploading';
                $counts['cellsRendered'] += \count($wanted);

                if ($message->upload) {
                    $this->ship($tiles, $counts, $written);

                    // Recorded only after the tiles are in the store, so
                    // an interrupted batch is drawn again rather than
                    // skipped on the strength of a render that never
                    // finished arriving.
                    $this->ledger->record($checksums);
                }
            }

            // Cells only feed the renderer; 4.2 GB of them serves
            // nothing once they are drawn.
            $this->clear($this->cells->directory(), keepExtension: null);

            $counts['cellsDone'] += \count($batch);
            $this->progress->write($counts);
        }

        if ($message->upload) {
            $counts['phase'] = 'finishing';
            $this->progress->write($counts);
            $this->ship($tiles, $counts, $written);

            // Anything in the store this run did not write is left over
            // from a world that has since changed -- a demolished
            // building draws no tile, so its old one would be served
            // for ever. Swept at the end rather than cleared at the
            // start: at no point is the map missing tiles it needs.
            $counts['phase'] = 'sweeping';
            $this->progress->write($counts);

            $swept = $this->uploader->removeStale(
                'map/base',
                $written,
                function (int $removed) use (&$counts): void {
                    $counts['tilesRemoved'] = $removed;
                    $this->progress->write($counts);
                },
            );

            $counts['tilesRemoved'] = $swept['removed'];
        }

        $counts['state'] = RenderProgress::DONE;
        $counts['finishedAt'] = time();
        $this->progress->write($counts);
    }

    /**
     * Uploads what was just rendered, then removes it.
     *
     * Verified before deleting, never after: a tile that only half
     * arrived is gone for good once the local copy goes.
     *
     * @param array<string, mixed> $counts
     * @param list<string>         $written every key this run has produced
     */
    private function ship(string $tiles, array &$counts, array &$written): void
    {
        try {
            $this->shipOnce($tiles, $counts, $written);
        } catch (StopRequested) {
            return;
        }
    }

    private function shipOnce(string $tiles, array &$counts, array &$written): void
    {
        $before = $counts['tilesUploaded'];
        $lastWrite = 0.0;
        $counts['batchTotal'] = $this->countFiles($tiles);
        $counts['batchDone'] = 0;
        $counts['batchPending'] = $counts['batchTotal'];

        $result = $this->uploader->upload(
            $tiles,
            'map/base',
            function (string $key, int $sent, int $failed, int $bytes) use (&$counts, $before, &$lastWrite): void {
                if ($this->progress->stopRequested()) {
                    throw new StopRequested();
                }

                // Checked per tile, not per batch: a batch is hundreds
                // of uploads, and a pause that waits for the end of one
                // is a pause nobody believes.
                while ($this->progress->isPaused()) {
                    if ($this->progress->stopRequested()) {
                        throw new StopRequested();
                    }

                    if (($counts['phase'] ?? '') !== 'paused') {
                        $counts['phase'] = 'paused';
                        $this->progress->write($counts);
                    }

                    sleep(1);
                }

                if (($counts['phase'] ?? '') === 'paused') {
                    $counts['phase'] = 'uploading';
                }

                $counts['currentTile'] = basename($key);
                $counts['currentPath'] = $key;
                $counts['tilesUploaded'] = $before + $sent;
                $counts['batchDone'] = $sent + $failed;
                $counts['batchPending'] = max(0, ($counts['batchTotal'] ?? 0) - $sent - $failed);

                // Every tile updates the numbers, but the file is
                // written at most a few times a second: at 300 tiles a
                // second the writes would cost more than the uploads.
                $now = microtime(true);

                if ($now - $lastWrite > 0.25) {
                    $lastWrite = $now;
                    $this->progress->write($counts);
                }
            },
        );

        $counts['tilesUploaded'] = $before + $result['sent'];
        $counts['tilesSkipped'] += $result['skipped'];
        $counts['bytesUploaded'] += $result['bytes'];
        $counts['bytesHeld'] = $result['bytesHeld'];
        $counts['objectsHeld'] = $result['objectsHeld'];
        $counts['tilesFailed'] = 0;

        // Nobody knows how many tiles a world makes: it depends on what
        // stands in each cell. Counted from what the renderer produced,
        // not from what has been uploaded so far -- mid-batch the
        // latter is a fraction of it, and the estimate came out an
        // order of magnitude short.
        $counts['tilesProduced'] = ($counts['tilesProduced'] ?? 0) + ($counts['batchTotal'] ?? 0);

        if (($counts['cellsRendered'] ?? 0) > 0 && ($counts['cellsDone'] ?? 0) > 0) {
            $perCell = $counts['tilesProduced'] / $counts['cellsRendered'];

            // How much of the grid holds map. Measured on this run, but
            // only once enough of it is done to mean anything: the run
            // starts along an empty edge, where the ratio reads far too
            // low. Build 42's own share stands in until then.
            $occupied = ($counts['cellsDone'] ?? 0) >= self::SAMPLE_CELLS
                ? $counts['cellsRendered'] / $counts['cellsDone']
                : self::OCCUPIED_SHARE;
            $counts['tilesEstimated'] = (int) round(
                $perCell * $occupied * ($counts['cellsTotal'] ?? self::COLUMNS * self::ROWS),
            );
        }

        foreach ($result['keys'] as $key) {
            $written[] = $key;
        }

        $counts['phase'] = 'verifying';
        $this->progress->write($counts);

        // The upload already listed the store and knows what it wrote;
        // listing again for every batch is what made this quadratic.
        $missing = $this->uploader->verify($tiles, 'map/base', $result['inStore']);

        if ($missing === []) {
            // The .dzi descriptors stay: tiny, and the viewer needs them.
            $this->clear($tiles, keepExtension: '.dzi');

            return;
        }

        // A second pass over what the verify pass found missing: this
        // store refuses a fraction of requests with a working key, so a
        // tile that failed six times in a row is unlucky rather than
        // broken, and giving up would leave holes in the map.
        for ($round = 0; $round < self::RETRY_ROUNDS && $missing !== []; ++$round) {
            $counts['phase'] = 'retrying';
            $counts['retryRound'] = $round + 1;
            $counts['retryPending'] = \count($missing);
            $this->progress->write($counts);

            // Rising pauses: a store refusing a burst is usually over it
            // a few seconds later.
            sleep(2 << $round);

            $again = $this->uploader->upload($tiles, 'map/base');

            // Only the retried tiles count: this pass walks the whole
            // directory, so its skipped ones are tiles the first pass
            // already reported.
            $counts['tilesUploaded'] += $again['sent'];
            $counts['bytesUploaded'] += $again['bytes'];

            $missing = $this->uploader->verify($tiles, 'map/base', $again['inStore']);
        }

        unset($counts['retryRound'], $counts['retryPending']);

        if ($missing === []) {
            $this->clear($tiles, keepExtension: '.dzi');

            return;
        }

        $counts['tilesFailed'] = \count($missing);

        // What did arrive is removed even though some did not: leaving
        // the whole batch means the next one walks it again and counts
        // every tile a second time, which is how 1.6 GB in the store
        // was reported as 11.9.
        $this->clear($tiles, keepExtension: '.dzi', except: $missing, prefix: 'map/base');

        $this->logger->warning('Tiles did not reach the store after retrying; keeping them on disk.', [
            'count' => \count($missing),
            'first' => \array_slice($missing, 0, 5),
        ]);
    }

    private function countFiles(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $count = 0;

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        ) as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile()) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Every batch of every pass, one floor at a time.
     *
     * @return \Generator<array{int, list<array{int, int}>}>
     */
    private function passes(): \Generator
    {
        foreach (self::FLOOR_ORDER as $floor) {
            foreach ($this->batches() as $batch) {
                yield [$floor, $batch];
            }
        }
    }

    /** @return \Generator<list<array{int, int}>> */
    private function batches(): \Generator
    {
        $batch = [];

        for ($x = 0; $x < self::COLUMNS; ++$x) {
            for ($y = 0; $y < self::ROWS; ++$y) {
                $batch[] = [$x, $y];

                if (\count($batch) === self::BATCH) {
                    yield $batch;
                    $batch = [];
                }
            }
        }

        if ($batch !== []) {
            yield $batch;
        }
    }

    /**
     * @param list<string> $except keys, as the store names them, to leave alone
     */
    private function clear(
        string $directory,
        ?string $keepExtension,
        array $except = [],
        string $prefix = '',
    ): void {
        if (!is_dir($directory)) {
            return;
        }

        $keep = array_flip($except);

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }

            if ($entry->isDir()) {
                @rmdir($entry->getPathname());

                continue;
            }

            if ($keepExtension !== null && str_ends_with($entry->getFilename(), $keepExtension)) {
                continue;
            }

            $key = $prefix.'/'.ltrim(str_replace($directory, '', $entry->getPathname()), '/');

            if (isset($keep[$key])) {
                continue;
            }

            @unlink($entry->getPathname());
        }
    }
}
