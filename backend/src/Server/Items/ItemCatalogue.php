<?php

declare(strict_types=1);

namespace App\Server\Items;

use App\Entity\GameServer;
use App\Server\Bridge\BridgeFiles;
use App\Server\Bridge\ServerSession;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Every item the server knows, as the bridge last wrote it.
 *
 * The file is close to a megabyte and the bridge rewrites it only when
 * the server starts, so it is fetched once and kept until the file on
 * the server is newer than what is held. Checking that costs one small
 * read of a size and a timestamp rather than a megabyte.
 */
final readonly class ItemCatalogue
{
    /** Long: the session id below is what actually decides freshness. */
    private const TTL_SECONDS = 604800;

    /** Only for a bridge too old to stamp its files. */
    private const TTL_WITHOUT_SESSION_ID = 900;

    /** The catalogue is large; the tail reader has to be told so. */
    private const MAX_BYTES = 8388608;

    public function __construct(
        private FileBrowserInterface $files,
        private ServerSession $sessions,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     generatedAt: int|null,
     *     bridgeVersion: string|null,
     *     available: bool
     * }
     */
    public function forServer(GameServer $server, bool $refresh = false): array
    {
        $key = 'items.catalogue.'.$server->getId()->toRfc4122();
        $entry = $this->cache->getItem($key);
        $held = $entry->isHit() && \is_array($entry->get()) ? $entry->get() : null;

        if (!$refresh && $held !== null && $this->stillCurrent($server, $held)) {
            return $held;
        }

        $result = $this->fetch($server);

        // A failed read must not throw away a good copy: the server may
        // simply be restarting, and stale items beat none at all.
        if (!$result['available'] && $held !== null) {
            return $held;
        }

        if ($result['available']) {
            $result['checkedAt'] = time();
            $entry->set($result)->expiresAfter(self::TTL_SECONDS);
            $this->cache->save($entry);
        }

        return $result;
    }

    /**
     * True while the held copy belongs to the run of the server that is
     * going on now.
     *
     * The bridge stamps every file it writes with an id made once per
     * start, so a changed id means the server restarted and reloaded
     * everything, mods included. That id also rides along in players.json,
     * which the panel reads every few seconds anyway -- so this check
     * usually costs nothing at all.
     *
     * @param array<string, mixed> $held
     */
    private function stillCurrent(GameServer $server, array $held): bool
    {
        $known = $held['sessionId'] ?? null;

        // A bridge older than 0.7.0 stamps nothing; fall back to time.
        if (!\is_string($known) || $known === '') {
            return time() - (int) ($held['checkedAt'] ?? 0) < self::TTL_WITHOUT_SESSION_ID;
        }

        return $this->sessions->currentFor($server) === $known;
    }

    public function forget(GameServer $server): void
    {
        $this->cache->deleteItem('items.catalogue.'.$server->getId()->toRfc4122());
    }

    /** @return array<string, mixed> */
    private function fetch(GameServer $server): array
    {
        $empty = ['items' => [], 'generatedAt' => null, 'bridgeVersion' => null, 'available' => false];
        $config = $server->getFtpConfig();

        if ($config === null) {
            return $empty;
        }

        try {
            $raw = $this->files->readTail($config, BridgeFiles::ITEMS, self::MAX_BYTES);
        } catch (StorageException) {
            return $empty;
        }

        $payload = json_decode(trim($raw), true);

        if (!\is_array($payload) || !\is_array($payload['items'] ?? null)) {
            return $empty;
        }

        return [
            'items' => array_values(array_filter($payload['items'], \is_array(...))),
            'generatedAt' => isset($payload['generatedAt']) ? (int) $payload['generatedAt'] : null,
            'bridgeVersion' => isset($payload['bridgeVersion']) ? (string) $payload['bridgeVersion'] : null,
            'sessionId' => isset($payload['sessionId']) ? (string) $payload['sessionId'] : null,
            'fileSize' => \strlen($raw),
            'available' => true,
        ];
    }
}
