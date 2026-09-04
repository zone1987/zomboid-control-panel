<?php

declare(strict_types=1);

namespace App\Server\Rcon;

use App\Entity\GameServer;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The command list of a given server, fetched once and remembered.
 *
 * A server's command set only changes when it is updated or a mod is
 * added, so asking on every keystroke would be waste; the cache is
 * cleared explicitly when the operator asks for a refresh.
 */
final readonly class CommandCatalogueProvider
{
    private const TTL_SECONDS = 3600;

    public function __construct(
        private RconClientInterface $rcon,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /** @throws RconException */
    public function forServer(GameServer $server, bool $refresh = false): CommandCatalogue
    {
        $item = $this->cache->getItem($this->key($server));

        if (!$refresh && $item->isHit()) {
            $cached = $item->get();

            if ($cached instanceof CommandCatalogue) {
                return $cached;
            }
        }

        $config = $server->getRconConfig();

        if ($config === null) {
            throw new RconUnreachable('This server has no RCON configuration.');
        }

        $catalogue = CommandCatalogue::parse($this->rcon->execute($config, 'help'));

        // An unparseable reply is not worth remembering for an hour.
        if (!$catalogue->isEmpty()) {
            $item->set($catalogue)->expiresAfter(self::TTL_SECONDS);
            $this->cache->save($item);
        }

        return $catalogue;
    }

    public function forget(GameServer $server): void
    {
        $this->cache->deleteItem($this->key($server));
    }

    private function key(GameServer $server): string
    {
        return 'rcon.commands.'.$server->getId()->toRfc4122();
    }
}
