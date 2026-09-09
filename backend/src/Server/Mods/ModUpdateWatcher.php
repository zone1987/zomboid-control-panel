<?php

declare(strict_types=1);

namespace App\Server\Mods;

use App\Entity\GameServer;
use App\Server\Events\PanelEvent;
use App\Server\Events\PanelEventDispatcher;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Notices when Steam has a newer copy of an installed mod.
 *
 * Modelled on BridgeWatcher: **only a change is announced, never the
 * state.** An update that is still waiting tomorrow is not news again,
 * and a message every hour for the same mod would train the operator to
 * ignore the channel.
 *
 * The marker is a position rather than a cache — losing it means one
 * silent update, so `ServerCacheCleaner` leaves this prefix alone the
 * way it leaves the bridge's.
 */
final readonly class ModUpdateWatcher
{
    public function __construct(
        private ModListReader $reader,
        private ManifestReader $manifests,
        private WorkshopSource $workshop,
        private PanelEventDispatcher $events,
        private CacheItemPoolInterface $cache,
        private ModManager $mods,
    ) {
    }

    /**
     * @return list<string> the ids newly found to have an update
     */
    public function check(GameServer $server): array
    {
        $config = $server->getFtpConfig();

        if ($config === null) {
            return [];
        }

        $location = $this->mods->locate($server);

        if (!$location->isUsable()) {
            return [];
        }

        $manifest = $this->manifests->read($config);

        if (!$manifest->isKnown()) {
            // Could not read Steam's record: saying nothing is right,
            // since "no update" and "could not tell" are different and
            // only one of them is worth a message (rule 6c).
            return [];
        }

        $waiting = [];

        foreach ($this->reader->read($config, (string) $location->path)->workshopIds as $workshopId) {
            if ($manifest->hasUpdate($workshopId) === true) {
                $waiting[] = $workshopId;
            }
        }

        $marker = $this->cache->getItem('events.mods.'.$server->getId()->toRfc4122());
        $announced = $marker->isHit() && \is_array($marker->get()) ? $marker->get() : null;

        $marker->set($waiting);
        $this->cache->save($marker);

        // Nothing to compare against on a first run: remember and stay
        // quiet, or every server would announce its whole backlog once.
        if ($announced === null) {
            return [];
        }

        $fresh = array_values(array_diff($waiting, $announced));

        if ($fresh === []) {
            return [];
        }

        $this->events->dispatch(PanelEvent::ofServer('mods.update', $server, [
            'input.mods' => implode(', ', $this->name($fresh)),
        ]));

        return $fresh;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function name(array $ids): array
    {
        $titles = [];

        foreach ($this->workshop->itemsById($ids)->items as $item) {
            $titles[$item->workshopId] = $item->title;
        }

        return array_map(static fn (string $id): string => $titles[$id] ?? $id, $ids);
    }
}
