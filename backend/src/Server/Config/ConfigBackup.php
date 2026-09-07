<?php

declare(strict_types=1);

namespace App\Server\Config;

use App\Entity\FtpConfig;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;

/**
 * Keeps copies of a settings file before it is written.
 *
 * A wrong value in one of these files stops a real server booting, and
 * the operator may not find out until the next restart. So every write
 * is preceded by a copy, and the copies are listed and restorable.
 *
 * **Three states, not two.** Backed up, nothing to back up (the file did
 * not exist — harmless, and the first write to a new file is exactly
 * that), and *failed* (dangerous). Collapsing the last two produced a
 * reference-panel answer claiming a backup that did not exist.
 */
final readonly class ConfigBackup
{
    /** How many to keep per file. Older ones are pruned oldest-first. */
    public const KEEP = 5;

    private const SUFFIX = '.zc-bak-';

    public function __construct(private FileBrowserInterface $files)
    {
    }

    /**
     * Copies `$path` beside itself, stamped with the time.
     *
     * @return array{state: 'backed-up'|'nothing-to-back-up'|'failed', path: string|null, error: string|null}
     */
    public function create(FtpConfig $config, string $path, string $contents): array
    {
        try {
            if (!$this->files->fileExists($config, $path)) {
                return ['state' => 'nothing-to-back-up', 'path' => null, 'error' => null];
            }

            $target = $path.self::SUFFIX.gmdate('Ymd-His');

            $this->files->upload($config, $target, $contents);
        } catch (StorageException $exception) {
            return ['state' => 'failed', 'path' => null, 'error' => $exception->messageKey()];
        }

        return ['state' => 'backed-up', 'path' => $target, 'error' => null];
    }

    /**
     * The backups of one file, newest first.
     *
     * **Sorted by name, never by timestamp.** On ext4 two backups made
     * in the same second carry identical modification times, and sorting
     * by them can delete the one just created as though it were the
     * oldest. The name holds the time to the second and sorts correctly
     * as text.
     *
     * @return list<array{name: string, path: string, size: int|null, takenAt: string}>
     *
     * @throws StorageException
     */
    public function list(FtpConfig $config, string $path): array
    {
        $directory = \dirname($path);
        $prefix = basename($path).self::SUFFIX;

        $entries = $this->files->listDirectory($config, $directory === '.' ? '' : $directory)['entries'];

        $found = [];

        foreach ($entries as $entry) {
            if ($entry['type'] !== 'file' || !str_starts_with($entry['name'], $prefix)) {
                continue;
            }

            $found[] = [
                'name' => $entry['name'],
                'path' => $entry['path'],
                'size' => $entry['size'],
                'takenAt' => substr($entry['name'], \strlen($prefix)),
            ];
        }

        usort($found, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $found;
    }

    /**
     * Removes all but the newest `KEEP`.
     *
     * Failing to prune is not worth failing a write over: the values are
     * already saved, and an extra old copy harms nobody.
     *
     * @return list<string> what was removed
     */
    public function prune(FtpConfig $config, string $path): array
    {
        try {
            $backups = $this->list($config, $path);
        } catch (StorageException) {
            return [];
        }

        $removed = [];

        foreach (\array_slice($backups, self::KEEP) as $old) {
            try {
                $this->files->delete($config, $old['path']);
                $removed[] = $old['name'];
            } catch (StorageException) {
                // Kept rather than reported: pruning is housekeeping.
            }
        }

        return $removed;
    }
}
