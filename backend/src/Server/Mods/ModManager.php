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
    ) {
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
        $build = GameBuild::of($server->getGameBuild());

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
            'gameBuild' => $server->getGameBuild(),
            'items' => $items,
        ];
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
            'items' => [],
        ];
    }
}
