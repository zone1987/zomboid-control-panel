<?php

declare(strict_types=1);

namespace App\Server\Map;

use App\Entity\GameServer;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;

/**
 * Fetches the map data for one cell from the game server.
 *
 * The server carries the geometry the renderer needs -- which wall
 * stands where, which shelf in which room -- as world_X_Y.lotpack plus
 * its header. Verified byte-identical to a local installation's copy,
 * and about 1 MB a cell, roughly 0.7 seconds over FTP.
 *
 * That is why nobody has to copy 4.2 GB of map anywhere: cells arrive
 * when somebody looks at them, always in the version the server is
 * actually running.
 */
final readonly class CellFetcher
{
    /** Where a map's cells live on a server. */
    public const MAP_DIRECTORY = 'media/maps/Muldraugh, KY';

    public function __construct(
        private FileBrowserInterface $files,
        private string $directory,
    ) {
    }

    /** Whether both files for this cell are already here. */
    public function has(int $x, int $y): bool
    {
        foreach ($this->namesFor($x, $y) as $name) {
            if (!is_file($this->directory.'/'.$name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Brings a cell down, unless it is already here.
     *
     * @return bool whether the cell is now available
     */
    public function fetch(GameServer $server, int $x, int $y): bool
    {
        if ($this->has($x, $y)) {
            return true;
        }

        $config = $server->getFtpConfig();

        if ($config === null) {
            return false;
        }

        @mkdir($this->directory, 0o775, true);

        foreach ($this->namesFor($x, $y) as $name) {
            $target = $this->directory.'/'.$name;

            if (is_file($target)) {
                continue;
            }

            $remote = self::MAP_DIRECTORY.'/'.$name;

            try {
                if (!$this->files->fileExists($config, $remote)) {
                    // A cell outside the map is not an error: the world
                    // is not a rectangle, and the renderer skips it.
                    return false;
                }

                // Written beside the target and moved, so a half-fetched
                // cell is never mistaken for a complete one.
                $partial = $target.'.part';
                $this->files->download($config, $remote, $partial);
                rename($partial, $target);
            } catch (StorageException) {
                @unlink($target.'.part');

                return false;
            }
        }

        return true;
    }

    /**
     * The lotpack's checksum, once the cell is here.
     *
     * What decides whether a cell needs drawing again: the same bytes
     * make the same picture.
     */
    public function checksumFor(int $x, int $y): ?string
    {
        $path = $this->directory.'/'.sprintf('world_%d_%d.lotpack', $x, $y);

        return is_file($path) ? (md5_file($path) ?: null) : null;
    }

    /** @return list<string> */
    private function namesFor(int $x, int $y): array
    {
        return [
            sprintf('%d_%d.lotheader', $x, $y),
            sprintf('world_%d_%d.lotpack', $x, $y),
        ];
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** How much map data is being kept. */
    public function bytesHeld(): int
    {
        $total = 0;

        foreach (glob($this->directory.'/*') ?: [] as $path) {
            $total += (int) filesize($path);
        }

        return $total;
    }
}
