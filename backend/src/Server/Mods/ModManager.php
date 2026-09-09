<?php

declare(strict_types=1);

namespace App\Server\Mods;

use App\Entity\GameServer;
use App\Server\Config\ConfigFileLocator;
use App\Server\Storage\StorageException;

/**
 * The mod list of one server, and what the workshop says about it.
 *
 * Holds the two things a controller should not: finding the right file,
 * and deciding what an unresolvable id means. An id the workshop cannot
 * describe is still installed -- it is shown as unresolved rather than
 * dropped, because dropping it would hide a mod the server really loads.
 */
final readonly class ModManager
{
    public function __construct(
        private ConfigFileLocator $locator,
        private ModListReader $reader,
        private ModListWriter $writer,
        private WorkshopSource $workshop,
        private ModInfoReader $modInfo,
        private DependencyGraph $graph,
        private BuildSource $builds,
    ) {
    }

    /** Which build this server runs, and where that was learnt. */
    public function build(GameServer $server): BuildReading
    {
        return $this->builds->resolve($server);
    }

    public function locate(GameServer $server): ModFileLocation
    {
        $config = $server->getFtpConfig();

        if ($config === null) {
            return ModFileLocation::noTransfer();
        }

        $files = $this->locator->locate($config);
        $candidates = $files['ini'];
        $directory = $files['directory'];

        if ($candidates === [] || $directory === null) {
            return ModFileLocation::noFile();
        }

        if (\count($candidates) > 1) {
            return ModFileLocation::ambiguous(\count($candidates));
        }

        return ModFileLocation::found(rtrim($directory, '/').'/'.$candidates[0]);
    }

    /**
     * What the server has installed, described where the workshop can.
     *
     * @return array{
     *     state: string,
     *     path: string|null,
     *     missingKeys: list<string>,
     *     workshopState: string,
     *     hasKey: bool,
     *     maps: list<string>,
     *     modIds: list<string>,
     *     gameBuild: string|null,
     *     items: list<array<string, mixed>>
     * }
     */
    public function installed(GameServer $server): array
    {
        $location = $this->locate($server);

        if (!$location->isUsable()) {
            return self::emptyInstalled($location);
        }

        $config = $server->getFtpConfig();
        \assert($config !== null);

        try {
            $list = $this->reader->read($config, (string) $location->path);
            $missing = $this->reader->missingKeys($config, (string) $location->path);
        } catch (StorageException) {
            return self::emptyInstalled(ModFileLocation::noFile());
        }

        $described = $this->workshop->itemsById($list->workshopIds);
        $byId = [];

        foreach ($described->items as $item) {
            $byId[$item->workshopId] = $item;
        }

        $items = [];

        // Driven by the file, not by the workshop answer: an id Steam
        // cannot describe is still an id the server will try to load.
        $reading = $this->builds->resolve($server);
        $build = $reading->build;

        foreach ($list->workshopIds as $workshopId) {
            $item = $byId[$workshopId] ?? null;

            $items[] = ModPresenter::present(
                $workshopId,
                $item,
                $build,
                '/api/servers/'.$server->getId()->toRfc4122().'/mods',
            );
        }

        return [
            'state' => $location->state,
            'path' => $location->path,
            'missingKeys' => $missing,
            'workshopState' => $described->state->value,
            'hasKey' => $this->workshop->hasKey(),
            'maps' => $list->maps,
            'modIds' => $list->modIds,
            'gameBuild' => $reading->build->number,
            'buildReading' => $reading->toArray(),
            'items' => $items,
        ];
    }

    /**
     * What is wrong with this server's mod list, if anything.
     *
     * Its own call rather than part of `installed()`: this costs
     * several workshop lookups and an FTP walk per mod, and the list
     * itself has to stay quick enough to poll.
     *
     * @return array<string, mixed>
     */
    public function diagnose(GameServer $server): array
    {
        $location = $this->locate($server);

        if (!$location->isUsable()) {
            return [
                'state' => $location->state,
                'modIds' => [],
                'missingDependencies' => [],
                'loadOrder' => LoadOrderVerdict::sorted([], false)->toArray(),
                'truncated' => false,
                'orphanedModIds' => [],
                'unmappedWorkshopIds' => [],
            ];
        }

        $config = $server->getFtpConfig();
        \assert($config !== null);

        try {
            $list = $this->reader->read($config, (string) $location->path);
        } catch (StorageException) {
            return [
                'state' => 'unreachable',
                'modIds' => [],
                'missingDependencies' => [],
                'loadOrder' => LoadOrderVerdict::sorted([], false)->toArray(),
                'truncated' => false,
                'orphanedModIds' => [],
                'unmappedWorkshopIds' => [],
            ];
        }

        // Which mod ids each workshop item actually contains. Read per
        // item because only the item itself knows.
        $byWorkshopId = [];
        $allModIds = [];

        foreach ($list->workshopIds as $workshopId) {
            $verdict = $this->modInfo->read($config, $workshopId);
            $byWorkshopId[$workshopId] = $verdict->toArray();

            foreach ($verdict->ids as $modId) {
                $allModIds[] = $modId;
            }
        }

        $resolution = $this->graph->resolve($list->workshopIds);
        $missing = $resolution->succeeded()
            ? DependencyGraph::missing($list->workshopIds, $resolution->required)
            : [];

        $order = LoadOrder::sort($list->modIds, self::modIdEdges($resolution->edges, $byWorkshopId));

        return [
            'state' => 'found',
            'modIds' => $byWorkshopId,
            'missingDependencies' => $missing,
            'loadOrder' => $order->toArray(),
            'truncated' => $resolution->truncated,
            // In Mods= but belonging to no installed workshop item: the
            // server will try to load something it never downloaded.
            'orphanedModIds' => array_values(array_diff($list->modIds, $allModIds)),
            // Downloaded but absent from Mods=, so the item is fetched
            // and then never loaded — a silent way to wonder why a mod
            // "does nothing".
            'unmappedWorkshopIds' => array_values(array_filter(
                $list->workshopIds,
                static function (string $workshopId) use ($byWorkshopId, $list): bool {
                    $known = $byWorkshopId[$workshopId]['ids'] ?? [];

                    return $known !== [] && array_diff($known, $list->modIds) === $known;
                },
            )),
        ];
    }

    /**
     * Writes the sorted load order back to `Mods=`.
     *
     * Refuses on a cycle rather than writing some order anyway: an
     * order that cannot be right is worse than the one already there,
     * which at least the operator knows about.
     *
     * @return array<string, mixed>
     */
    public function applyLoadOrder(GameServer $server): array
    {
        $location = $this->locate($server);

        if (!$location->isUsable()) {
            return ['status' => $location->state, 'missingKeys' => []];
        }

        $config = $server->getFtpConfig();
        \assert($config !== null);

        $diagnosis = $this->diagnose($server);
        $order = $diagnosis['loadOrder'];

        if ($order['state'] !== 'sorted') {
            return ['status' => 'cycle', 'missingKeys' => [], 'tangled' => $order['tangled']];
        }

        if ($order['changed'] !== true) {
            // Saying so beats writing the same value and reporting
            // success, which would look like it had done something.
            return ['status' => 'alreadyOrdered', 'missingKeys' => []];
        }

        $list = $this->reader->read($config, (string) $location->path);
        $next = new ModList($list->workshopIds, $order['order'], $list->maps);

        return $this->writer->write($config, (string) $location->path, $next);
    }

    /**
     * Turns workshop-id edges into mod-id edges, which is what `Mods=`
     * is ordered by.
     *
     * @param list<array{0: string, 1: string}> $edges
     * @param array<string, array<string, mixed>> $byWorkshopId
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function modIdEdges(array $edges, array $byWorkshopId): array
    {
        $out = [];

        foreach ($edges as [$parent, $child]) {
            foreach ($byWorkshopId[$parent]['ids'] ?? [] as $parentModId) {
                foreach ($byWorkshopId[$child]['ids'] ?? [] as $childModId) {
                    $out[] = [$parentModId, $childModId];
                }
            }
        }

        return $out;
    }

    /**
     * Adds or removes one workshop id.
     *
     * The mod ids that `Mods=` needs are not touched here: they come
     * from the mod's own info.txt on the server, which does not exist
     * until the server has downloaded it.
     *
     * @return array<string, mixed>
     */
    public function change(GameServer $server, string $workshopId, bool $add): array
    {
        $location = $this->locate($server);

        if (!$location->isUsable()) {
            return ['status' => $location->state, 'missingKeys' => []];
        }

        $config = $server->getFtpConfig();
        \assert($config !== null);

        $list = $this->reader->read($config, (string) $location->path);
        $next = $add ? $list->withWorkshopId($workshopId) : $list->withoutWorkshopId($workshopId);

        // Nothing to write is worth saying: a silent success would look
        // identical to a change that never happened.
        if ($next->workshopIds === $list->workshopIds) {
            return ['status' => $add ? 'alreadyInstalled' : 'notInstalled', 'missingKeys' => []];
        }

        return $this->writer->write($config, (string) $location->path, $next);
    }

    /**
     * @return array{
     *     state: string, path: string|null, missingKeys: list<string>,
     *     workshopState: string, hasKey: bool, maps: list<string>,
     *     modIds: list<string>, gameBuild: string|null,
     *     items: list<array<string, mixed>>
     * }
     */
    private static function emptyInstalled(ModFileLocation $location): array
    {
        return [
            'state' => $location->state,
            'path' => $location->path,
            'missingKeys' => [],
            'workshopState' => WorkshopState::Ok->value,
            'hasKey' => false,
            'maps' => [],
            'modIds' => [],
            'gameBuild' => null,
            'buildReading' => null,
            'items' => [],
        ];
    }
}
