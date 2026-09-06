<?php

declare(strict_types=1);

namespace App\Server\Map;

use App\Storage\ObjectStorageInterface;
use Psr\Log\LoggerInterface;

/**
 * What each cell looked like when it was last rendered.
 *
 * A cell whose lotpack is byte for byte what it was needs no second
 * render: the tiles in the store are already the picture of it. The
 * ledger lives beside them so it survives a redeploy, and one fetch of
 * the cell's own data -- a megabyte, under a second -- decides.
 */
final class CellLedger
{
    private const KEY = 'map/cells.json';

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ObjectStorageInterface $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, string> cell key to lotpack checksum */
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
                $decoded = json_decode($filesystem->read(self::KEY), true, 4, \JSON_THROW_ON_ERROR);
                $this->cache = \is_array($decoded) ? $decoded : [];
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Reading the cell ledger failed.', [
                'error' => mb_substr($exception->getMessage(), 0, 200),
            ]);
        }

        return $this->cache;
    }

    public function matches(int $x, int $y, string $checksum, int $floor = 0): bool
    {
        return ($this->all()[self::name($x, $y, $floor)] ?? null) === $checksum;
    }

    /** @param array<string, string> $checksums */
    public function record(array $checksums): void
    {
        if ($checksums === [] || !$this->storage->isConfigured()) {
            return;
        }

        $this->cache = array_merge($this->all(), $checksums);

        try {
            $this->storage->create()->write(self::KEY, json_encode($this->cache, \JSON_THROW_ON_ERROR));
        } catch (\Throwable $exception) {
            $this->logger->warning('Writing the cell ledger failed.', [
                'error' => mb_substr($exception->getMessage(), 0, 200),
            ]);
        }
    }

    /**
     * Forgets every checksum.
     *
     * For a run starting afresh: a cell whose lotpack is unchanged has
     * still to be drawn again when the geometry changed under it, and
     * the ledger would otherwise skip it on the strength of tiles that
     * no longer belong to the pyramid being built.
     */
    public function clear(): void
    {
        $this->cache = [];

        if (!$this->storage->isConfigured()) {
            return;
        }

        try {
            $filesystem = $this->storage->create();

            if ($filesystem->fileExists(self::KEY)) {
                $filesystem->delete(self::KEY);
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Clearing the cell ledger failed.', [
                'error' => mb_substr($exception->getMessage(), 0, 200),
            ]);
        }
    }

    /** Keyed by floor as well: each pass draws a different one. */
    public static function name(int $x, int $y, int $floor = 0): string
    {
        return $x.','.$y.'@'.$floor;
    }
}
