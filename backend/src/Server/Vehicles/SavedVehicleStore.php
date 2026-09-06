<?php

declare(strict_types=1);

namespace App\Server\Vehicles;

use App\Entity\GameServer;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Every vehicle the world has, read from the server's own vehicles.db.
 *
 * The bridge can only see vehicles in chunks a player keeps loaded,
 * which on an empty server is none at all. This database holds each one
 * the world has generated, so the map is populated whether or not
 * anybody is online.
 *
 * Vehicles in never-visited regions are absent by design: the game
 * generates them the first time a chunk loads and writes them here
 * straight away, so nothing exists to read before that.
 */
final readonly class SavedVehicleStore implements VehicleSourceInterface
{
    /** Under the save directory, which is found rather than assumed. */
    private const FILENAME = 'vehicles.db';

    private const SAVES = 'Saves/Multiplayer';

    /**
     * Downloading and opening the file costs an FTP round trip, and the
     * game only commits it periodically, so a short cache is free
     * accuracy-wise and saves the map polling it every few seconds.
     */
    private const CACHE_SECONDS = 30;

    public function __construct(
        private FileBrowserInterface $files,
        private SavedVehicleReader $reader,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private string $workingDirectory,
    ) {
    }

    /**
     * Null when the server has no readable database, which is not an
     * error: a fresh server has not written one yet.
     *
     * @return list<array<string, mixed>>|null
     */
    public function vehicles(GameServer $server): ?array
    {
        $id = $server->getId();

        if ($id === null) {
            return null;
        }

        return $this->cache->get(
            'saved-vehicles-'.$id,
            function (ItemInterface $item) use ($server): ?array {
                $item->expiresAfter(self::CACHE_SECONDS);

                return $this->load($server);
            },
        );
    }

    /** @return list<array<string, mixed>>|null */
    private function load(GameServer $server): ?array
    {
        $config = $server->getFtpConfig();

        if ($config === null) {
            return null;
        }

        $remote = $this->databasePath($server);

        if ($remote === null) {
            return null;
        }

        $local = $this->workingDirectory.'/vehicles-'.$server->getId().'.db';

        if (!is_dir($this->workingDirectory)) {
            mkdir($this->workingDirectory, 0o775, true);
        }

        try {
            $this->files->download($config, $remote, $local);
        } catch (StorageException $exception) {
            $this->logger->info('Could not fetch the vehicle database.', [
                'server' => $server->getId(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        try {
            return $this->readFile($local);
        } finally {
            @unlink($local);
        }
    }

    /** @return list<array<string, mixed>> */
    private function readFile(string $path): array
    {
        // Read-only, so the panel can never write into a save file.
        $database = new \PDO('sqlite:'.$path, options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $vehicles = [];
        $skipped = 0;

        /** @var array{id: string, x: string, y: string, data: string} $row */
        foreach ($database->query('SELECT id, x, y, data FROM vehicles') as $row) {
            $vehicle = $this->reader->read(
                (int) $row['id'],
                (float) $row['x'],
                (float) $row['y'],
                $row['data'],
            );

            if ($vehicle === null) {
                ++$skipped;

                continue;
            }

            $vehicles[] = $vehicle->toArray();
        }

        if ($skipped > 0) {
            $this->logger->info('Some vehicle rows did not decode.', [
                'skipped' => $skipped,
                'read' => \count($vehicles),
            ]);
        }

        return $vehicles;
    }

    /**
     * Where the database is, found by looking.
     *
     * The world's name is chosen by whoever set the server up, so the
     * path cannot be assumed. Only the save directory's own children
     * are considered, and each name is checked before it is used in a
     * path.
     */
    private function databasePath(GameServer $server): ?string
    {
        $config = $server->getFtpConfig();

        if ($config === null) {
            return null;
        }

        try {
            $listing = $this->files->listDirectory($config, self::SAVES);
        } catch (StorageException) {
            return null;
        }

        foreach ($listing['entries'] as $entry) {
            if ($entry['type'] !== 'directory' || preg_match('/^[\w.\- ]+$/', $entry['name']) !== 1) {
                continue;
            }

            $candidate = self::SAVES.'/'.$entry['name'].'/'.self::FILENAME;

            try {
                if ($this->files->fileExists($config, $candidate)) {
                    return $candidate;
                }
            } catch (StorageException) {
                continue;
            }
        }

        return null;
    }
}
