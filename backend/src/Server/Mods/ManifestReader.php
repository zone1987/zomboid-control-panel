<?php

declare(strict_types=1);

namespace App\Server\Mods;

use App\Entity\FtpConfig;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;

/**
 * Reads Steam's own record of what it downloaded.
 *
 * The file is VDF — Valve's key-value format, quoted strings and braces
 * — and only three of its keys matter, so it is parsed by hand rather
 * than fully: a partial read of a well-known shape beats a general
 * parser for a file this small.
 */
final readonly class ManifestReader
{
    private const PATH = 'steamapps/workshop/appworkshop_108600.acf';

    /** Generous for a file measured at under a kilobyte. */
    private const MAX_BYTES = 1048576;

    public function __construct(private FileBrowserInterface $files)
    {
    }

    public function read(?FtpConfig $config): WorkshopManifest
    {
        if ($config === null) {
            return WorkshopManifest::noTransfer();
        }

        try {
            if (!$this->files->fileExists($config, self::PATH)) {
                // Steam writes this the first time it downloads
                // anything, so its absence means nothing was ever
                // installed -- benign, not broken.
                return WorkshopManifest::absent();
            }

            $raw = $this->files->readTail($config, self::PATH, self::MAX_BYTES);
        } catch (StorageException) {
            return WorkshopManifest::unreachable();
        }

        return self::parse($raw);
    }

    public static function parse(string $raw): WorkshopManifest
    {
        $installed = self::block($raw, 'WorkshopItemsInstalled');
        $details = self::block($raw, 'WorkshopItemDetails');

        $items = [];

        foreach (self::entries($installed) as $id => $fields) {
            $items[$id] = [
                'size' => (int) ($fields['size'] ?? 0),
                'timeUpdated' => (int) ($fields['timeupdated'] ?? 0),
                'latestTimeUpdated' => 0,
            ];
        }

        // The two blocks list the same ids, but only the second knows
        // what Steam has -- and an id in details that is not installed
        // is something Steam is aware of rather than something present.
        foreach (self::entries($details) as $id => $fields) {
            if (!isset($items[$id])) {
                continue;
            }

            $items[$id]['latestTimeUpdated'] = (int) ($fields['latest_timeupdated'] ?? 0);

            if ($items[$id]['timeUpdated'] === 0) {
                $items[$id]['timeUpdated'] = (int) ($fields['timeupdated'] ?? 0);
            }
        }

        $size = preg_match('/"SizeOnDisk"\s+"(\d+)"/i', $raw, $match) === 1 ? (int) $match[1] : 0;

        return WorkshopManifest::read($items, $size);
    }

    /**
     * The body of one named block, brace-counted.
     *
     * A regex to the closing brace would stop at the first one, and
     * these blocks nest one level deep.
     */
    private static function block(string $raw, string $name): string
    {
        $start = stripos($raw, '"'.$name.'"');

        if ($start === false) {
            return '';
        }

        $open = strpos($raw, '{', $start);

        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = \strlen($raw);

        for ($i = $open; $i < $length; ++$i) {
            if ($raw[$i] === '{') {
                ++$depth;
            } elseif ($raw[$i] === '}') {
                --$depth;

                if ($depth === 0) {
                    return substr($raw, $open + 1, $i - $open - 1);
                }
            }
        }

        return '';
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function entries(string $block): array
    {
        $entries = [];

        // "<id>" { ... } pairs; the id is always numeric here.
        if (preg_match_all('/"(\d{1,20})"\s*\{([^}]*)\}/s', $block, $matches, \PREG_SET_ORDER) === false) {
            return $entries;
        }

        foreach ($matches as [, $id, $body]) {
            $fields = [];

            if (preg_match_all('/"([^"]+)"\s+"([^"]*)"/', $body, $pairs, \PREG_SET_ORDER) !== false) {
                foreach ($pairs as [, $key, $value]) {
                    $fields[strtolower($key)] = $value;
                }
            }

            $entries[$id] = $fields;
        }

        return $entries;
    }
}
