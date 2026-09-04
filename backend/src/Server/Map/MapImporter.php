<?php

declare(strict_types=1);

namespace App\Server\Map;

use App\Entity\GameServer;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;

/**
 * Takes the world map from a game server.
 *
 * The server's own files are the version it is actually running, which
 * a local copy of the game need not be -- and an operator renting one
 * has no local copy at all. It is a single 51 MB archive holding all
 * 6582 tiles, so it streams to disk in one download.
 */
final readonly class MapImporter
{
    /**
     * Where a pyramid lives on a server.
     *
     * Only the main map carries one; the other directories are start
     * areas inside it. Listed rather than searched because that
     * directory runs to thousands of entries.
     */
    public const CANDIDATES = [
        'media/maps/Muldraugh, KY/pyramid.zip',
    ];

    public function __construct(
        private FileBrowserInterface $files,
        private MapTileStore $store,
    ) {
    }

    /**
     * @return array{tiles: int, bytes: int, source: string}
     *
     * @throws MapImportFailed
     */
    public function importFrom(GameServer $server): array
    {
        $config = $server->getFtpConfig();

        if ($config === null) {
            throw new MapImportFailed('That server has no file access.', 'servers.ftpNotConfigured');
        }

        foreach (self::CANDIDATES as $path) {
            if (!$this->files->fileExists($config, $path)) {
                continue;
            }

            $temporary = sys_get_temp_dir().'/'.uniqid('pz-map-', true).'.zip';

            try {
                $bytes = $this->files->download($config, $path, $temporary);
                $tiles = $this->install($temporary);
            } catch (StorageException $exception) {
                @unlink($temporary);

                throw new MapImportFailed($exception->getMessage(), $exception->messageKey());
            } finally {
                @unlink($temporary);
            }

            return ['tiles' => $tiles, 'bytes' => $bytes, 'source' => $path];
        }

        throw new MapImportFailed(
            'That server has no map pyramid under media/maps.',
            'map.noPyramidOnServer',
        );
    }

    /**
     * Counts the tiles and puts the archive in place.
     *
     * Counted first: an archive that holds none is not a map, and
     * finding that out after replacing the working one would be worse.
     *
     * @throws MapImportFailed
     */
    public function install(string $archivePath): int
    {
        $archive = new \ZipArchive();

        if ($archive->open($archivePath) !== true) {
            throw new MapImportFailed('That file is not a readable zip archive.', 'map.notAnArchive');
        }

        $tiles = 0;

        for ($index = 0; $index < $archive->numFiles; ++$index) {
            if (str_ends_with((string) $archive->getNameIndex($index), '.png')) {
                ++$tiles;
            }
        }

        $archive->close();

        if ($tiles === 0) {
            throw new MapImportFailed('The archive holds no tiles.', 'map.noTilesInArchive');
        }

        $target = $this->store->archivePath();
        @mkdir(\dirname($target), 0o775, true);

        if (!copy($archivePath, $target)) {
            throw new MapImportFailed('The archive could not be put in place.', 'map.copyFailed');
        }

        return $tiles;
    }
}
