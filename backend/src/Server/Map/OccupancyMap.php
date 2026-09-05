<?php

declare(strict_types=1);

namespace App\Server\Map;

use App\Storage\ObjectStorageInterface;
use Psr\Log\LoggerInterface;

/**
 * Which floors and blocks of every cell hold anything.
 *
 * Surveying the world means fetching 4,065 cells over FTP, a quarter
 * of an hour. Holding the cells themselves would be 7.9 GB; the answer
 * they yield is 2.5 MB, so it is kept in the store and a second run
 * starts drawing immediately.
 *
 * It is discarded when a cell's lotpack changes, which the ledger
 * already notices.
 */
final class OccupancyMap
{
    private const KEY = 'map/occupancy.json';

    /** @var array<string, CellOccupancy>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ObjectStorageInterface $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, CellOccupancy> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = [];

        if (!$this->storage->isConfigured()) {
            return $this->cache;
        }

        try {
            $filesystem = $this->storage->create();

            if ($filesystem->fileExists(self::KEY)) {
                $decoded = json_decode($filesystem->read(self::KEY), true, 8, \JSON_THROW_ON_ERROR);

                foreach (\is_array($decoded) ? $decoded : [] as $name => $entry) {
                    if (\is_array($entry)) {
                        $this->cache[(string) $name] = CellOccupancy::fromStorage($entry);
                    }
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Reading the occupancy map failed.', [
                'error' => mb_substr($exception->getMessage(), 0, 200),
            ]);
        }

        return $this->cache;
    }

    /** @param array<string, CellOccupancy> $cells */
    public function record(array $cells): void
    {
        if ($cells === [] || !$this->storage->isConfigured()) {
            return;
        }

        $this->cache = array_merge($this->all(), $cells);

        try {
            $this->storage->create()->write(self::KEY, json_encode(
                array_map(static fn (CellOccupancy $cell): array => $cell->toArray(), $this->cache),
                \JSON_THROW_ON_ERROR,
            ));
        } catch (\Throwable $exception) {
            $this->logger->warning('Writing the occupancy map failed.', [
                'error' => mb_substr($exception->getMessage(), 0, 200),
            ]);
        }
    }

    public function forget(): void
    {
        $this->cache = null;

        if (!$this->storage->isConfigured()) {
            return;
        }

        try {
            $filesystem = $this->storage->create();

            if ($filesystem->fileExists(self::KEY)) {
                $filesystem->delete(self::KEY);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Discarding the occupancy map failed.', [
                'error' => mb_substr($exception->getMessage(), 0, 200),
            ]);
        }
    }
}
