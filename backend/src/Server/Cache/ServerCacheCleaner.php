<?php

declare(strict_types=1);

namespace App\Server\Cache;

use App\Entity\GameServer;
use App\Repository\GameServerRepository;
use App\Settings\SupportedLanguages;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Drops what the panel read from a server, so the next request asks
 * the server again.
 *
 * Three entries in the same pool are deliberately left alone:
 * `discord.chat.*` and `events.bridge.*` are positions in a stream, and
 * `bridge.sequence.*` is a command counter. Dropping those loses
 * messages or costs a resync rather than re-reading a fact.
 */
final readonly class ServerCacheCleaner
{
    public function __construct(
        private CacheItemPoolInterface $cache,
        private GameServerRepository $servers,
        private SupportedLanguages $languages,
    ) {
    }

    public function clear(): CacheClearVerdict
    {
        $servers = $this->servers->findAll();
        $keys = ['panel.latest_release'];

        foreach ($servers as $server) {
            foreach ($this->keysFor($server) as $key) {
                $keys[] = $key;
            }
        }

        try {
            $removed = $this->cache->deleteItems($keys);
        } catch (InvalidArgumentException $exception) {
            return CacheClearVerdict::failed($exception->getMessage());
        }

        if (!$removed) {
            return CacheClearVerdict::partiallyCleared(\count($keys), \count($servers));
        }

        return $servers === []
            ? CacheClearVerdict::nothingToClear()
            : CacheClearVerdict::cleared(\count($keys), \count($servers));
    }

    /** @return list<string> */
    private function keysFor(GameServer $server): array
    {
        $id = $server->getId()->toRfc4122();

        $keys = [
            'items.catalogue.'.$id,
            'vehicles.catalogue.'.$id,
            'saved-vehicles-'.$id,
            'rcon.commands.'.$id,
            'bridge.session.'.$id,
            'bridge.read.'.$id,
        ];

        foreach ($this->languages->all() as $language) {
            $code = strtoupper($language);
            $keys[] = sprintf('items.names.%s.%s', $id, $code);
            $keys[] = sprintf('vehicles.names.%s.%s', $id, $code);
        }

        return $keys;
    }
}
