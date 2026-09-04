<?php

declare(strict_types=1);

namespace App\Server\Bridge;

use App\Entity\GameServer;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Which run of the server is going on now.
 *
 * From 0.7.0 the bridge stamps every file with an id made once per
 * start. A changed id means the server restarted and reloaded
 * everything it knows -- items, mods, safehouses -- so anything the
 * panel is holding from before belongs to a world that no longer exists.
 *
 * Read from players.json, which is small and rewritten every few
 * seconds, rather than from the catalogue, which is a megabyte.
 */
final readonly class ServerSession
{
    /** Long enough that a burst of requests asks once. */
    private const TTL_SECONDS = 5;

    public function __construct(
        private FileBrowserInterface $files,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /** Null when the server is unreachable or its bridge predates 0.7.0. */
    public function currentFor(GameServer $server): ?string
    {
        $entry = $this->cache->getItem('bridge.session.'.$server->getId()->toRfc4122());

        if ($entry->isHit()) {
            $held = $entry->get();

            return \is_string($held) ? $held : null;
        }

        $id = $this->read($server);

        $entry->set($id)->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($entry);

        return $id;
    }

    private function read(GameServer $server): ?string
    {
        $config = $server->getFtpConfig();

        if ($config === null) {
            return null;
        }

        try {
            // The id sits in the first hundred bytes, and the file is
            // small enough that reading it whole costs nothing.
            $raw = $this->files->readTail($config, BridgeFiles::PLAYERS, 65536);
        } catch (StorageException) {
            return null;
        }

        $payload = json_decode(trim($raw), true);

        if (!\is_array($payload) || !\is_string($payload['sessionId'] ?? null)) {
            return null;
        }

        return $payload['sessionId'];
    }
}
