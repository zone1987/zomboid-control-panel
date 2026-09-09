<?php

declare(strict_types=1);

namespace App\Server\Mods;

use App\Entity\FtpConfig;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;

/**
 * Reads the mod ids a downloaded workshop item contains.
 *
 * `Mods=` takes these, `WorkshopItems=` takes the numeric id, and only
 * the item itself knows the mapping — Steam's API does not carry it.
 *
 * The file is **`mod.info`**, not `info.txt` as the game's own settings
 * tooltip still claims. Checked against a real server; both spellings
 * are accepted anyway, since the tooltip is what an operator reads.
 *
 * The layout varies and a fixed path finds only half of them: one item
 * had `mods/CommonSense/mod.info`, another `mods/PZ_Map/42/mod.info`,
 * where the extra level is the game build the variant is for. So the
 * search walks a bounded depth rather than assuming either shape.
 */
final readonly class ModInfoReader
{
    private const CONTENT_ROOT = 'steamapps/workshop/content/108600';

    /** `mods/<name>/<build>/mod.info` is the deepest real layout seen. */
    private const MAX_DEPTH = 3;

    private const FILE_NAMES = ['mod.info', 'info.txt'];

    /** A mod.info is a few hundred bytes; anything larger is not one. */
    private const MAX_BYTES = 65536;

    public function __construct(private FileBrowserInterface $files)
    {
    }

    public function read(?FtpConfig $config, string $workshopId): ModIdVerdict
    {
        if ($config === null) {
            return ModIdVerdict::noTransfer();
        }

        if (preg_match('/^\d{1,20}$/', $workshopId) !== 1) {
            return ModIdVerdict::notDownloaded();
        }

        $root = self::CONTENT_ROOT.'/'.$workshopId;

        try {
            if (!$this->files->directoryExists($config, self::CONTENT_ROOT)) {
                return ModIdVerdict::noWorkshopDirectory();
            }

            if (!$this->files->directoryExists($config, $root)) {
                // Benign and by far the commonest: the server downloads
                // an item at its next start, not when it is configured.
                return ModIdVerdict::notDownloaded();
            }

            $found = [];

            foreach ($this->modInfoPaths($config, $root, self::MAX_DEPTH) as $path) {
                $raw = $this->files->readTail($config, $path, self::MAX_BYTES);
                $entry = self::parse($raw);

                if ($entry['id'] !== null) {
                    $found[] = ['id' => $entry['id'], 'path' => $path, 'versionMin' => $entry['versionMin']];
                }
            }
        } catch (StorageException) {
            return ModIdVerdict::unreachable();
        }

        if ($found === []) {
            return ModIdVerdict::noModInfo();
        }

        $ids = [];
        $paths = [];
        $versionMin = null;

        foreach ($found as $entry) {
            if (!\in_array($entry['id'], $ids, true)) {
                $ids[] = $entry['id'];
            }

            $paths[] = $entry['path'];
            $versionMin ??= $entry['versionMin'];
        }

        return ModIdVerdict::found($ids, $paths, $versionMin);
    }

    /**
     * @return list<string>
     *
     * @throws StorageException
     */
    private function modInfoPaths(FtpConfig $config, string $directory, int $depth): array
    {
        if ($depth < 0) {
            return [];
        }

        $paths = [];

        foreach ($this->files->listDirectory($config, $directory)['entries'] as $entry) {
            $name = $entry['name'];

            // `directory`, not `dir` — ServerFileBrowser.php:53. Getting
            // this wrong made the walk stop at the first level and
            // report "no mod.info" for items that had one.
            if (\in_array($entry['type'], ['directory', 'dir'], true)) {
                $paths = [...$paths, ...$this->modInfoPaths($config, $entry['path'], $depth - 1)];

                continue;
            }

            if (\in_array(strtolower($name), self::FILE_NAMES, true)) {
                $paths[] = $entry['path'];
            }
        }

        return $paths;
    }

    /**
     * @return array{id: string|null, versionMin: string|null}
     */
    private static function parse(string $raw): array
    {
        $id = null;
        $versionMin = null;

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^\s*id\s*=\s*(.+?)\s*$/i', $line, $match) === 1 && $id === null) {
                $id = $match[1];
            }

            if (preg_match('/^\s*versionMin\s*=\s*(.+?)\s*$/i', $line, $match) === 1) {
                $versionMin = $match[1];
            }
        }

        return ['id' => $id === '' ? null : $id, 'versionMin' => $versionMin];
    }
}
