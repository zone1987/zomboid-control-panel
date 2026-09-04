<?php

declare(strict_types=1);

namespace App\Server\Logs;

use App\Entity\FtpConfig;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;

/**
 * Finds the log files a server is currently writing.
 *
 * Zomboid stamps the start time into every log name --
 * "2026-09-04_18-27_chat.txt" -- and begins a fresh set on each restart,
 * so a fixed path stops working the first time the server is restarted.
 */
final readonly class LogFileFinder
{
    /**
     * The kinds a Zomboid server writes, in the order they are useful to
     * an operator. The key is what the panel calls them; the value is the
     * suffix the game uses.
     */
    public const KINDS = [
        'chat' => 'chat.txt',
        'connections' => 'connections.txt',
        'server' => 'DebugLog-server.txt',
        'user' => 'user.txt',
        'commands' => 'cmd.txt',
        'pvp' => 'pvp.txt',
        'perks' => 'PerkLog.txt',
        'map' => 'map.txt',
        'items' => 'item.txt',
    ];

    public function __construct(private FileBrowserInterface $browser)
    {
    }

    /**
     * The newest file of each kind, keyed by kind.
     *
     * @return array<string, array{path: string, name: string, size: int|null, lastModified: int|null}>
     *
     * @throws StorageException
     */
    public function findAll(FtpConfig $config): array
    {
        $directory = $config->getLogPath() ?? 'Logs';
        $listing = $this->browser->listDirectory($config, $directory);

        $found = [];

        foreach ($listing['entries'] as $entry) {
            if ($entry['type'] !== 'file') {
                continue;
            }

            $kind = self::kindOf($entry['name']);

            if ($kind === null) {
                continue;
            }

            // Names sort chronologically because the stamp leads, so a
            // later name is a later server start.
            if (isset($found[$kind]) && strcmp($entry['name'], $found[$kind]['name']) <= 0) {
                continue;
            }

            $found[$kind] = [
                'path' => $entry['path'],
                'name' => $entry['name'],
                'size' => $entry['size'],
                'lastModified' => $entry['lastModified'],
            ];
        }

        return $found;
    }

    public static function kindOf(string $filename): ?string
    {
        foreach (self::KINDS as $kind => $suffix) {
            if (str_ends_with($filename, '_'.$suffix)) {
                return $kind;
            }
        }

        return null;
    }
}
