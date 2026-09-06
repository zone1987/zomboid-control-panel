<?php

declare(strict_types=1);

namespace App\Server\Vehicles;

use App\Entity\GameServer;
use App\Server\Bridge\BridgeFiles;
use App\Server\Bridge\ServerSession;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use App\Server\Vehicles\Models\ModelStore;
use App\Server\Vehicles\Models\VehicleCatalogue;
use App\Server\Vehicles\Models\VehicleNames;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Which vehicles a server can spawn, joined with the artwork to draw them.
 *
 * The list comes from the bridge because only the server knows what it
 * has loaded -- a shipped table would miss every modded vehicle, which
 * is the case an operator most wants. The model, texture and paint mask
 * come from the panel's own catalogue, generated from the game's
 * scripts, and the artwork files themselves are uploaded by the
 * operator because they are The Indie Stone's.
 */
final readonly class SpawnableVehicles
{
    /** Long: the session id below is what actually decides freshness. */
    private const TTL_SECONDS = 604800;

    /** Only for a bridge too old to stamp its files. */
    private const TTL_WITHOUT_SESSION_ID = 900;

    private const MAX_BYTES = 2097152;

    public function __construct(
        private FileBrowserInterface $files,
        private ServerSession $sessions,
        private CacheItemPoolInterface $cache,
        private ModelStore $models,
    ) {
    }

    /**
     * @return array{
     *     bodies: list<array{id: string, name: string, count: int, preview: string|null}>,
     *     items: list<array<string, mixed>>,
     *     generatedAt: int|null,
     *     bridgeVersion: string|null,
     *     available: bool
     * }
     */
    public function forServer(GameServer $server, bool $refresh = false): array
    {
        $key = 'vehicles.catalogue.'.$server->getId()->toRfc4122();
        $entry = $this->cache->getItem($key);
        $held = $entry->isHit() && \is_array($entry->get()) ? $entry->get() : null;

        if (!$refresh && $held !== null && $this->stillCurrent($server, $held)) {
            /** @var array{bodies: list<array{id: string, name: string, count: int, preview: string|null}>, items: list<array<string, mixed>>, generatedAt: int|null, bridgeVersion: string|null, available: bool} */
            return $held;
        }

        $result = $this->fetch($server);

        // A failed read must not throw away a good copy: the server may
        // simply be restarting.
        if (!$result['available'] && $held !== null) {
            /** @var array{bodies: list<array{id: string, name: string, count: int, preview: string|null}>, items: list<array<string, mixed>>, generatedAt: int|null, bridgeVersion: string|null, available: bool} */
            return $held;
        }

        $entry->set($result);
        $entry->expiresAfter(
            ($result['sessionId'] ?? null) === null ? self::TTL_WITHOUT_SESSION_ID : self::TTL_SECONDS,
        );
        $this->cache->save($entry);

        return $result;
    }

    public function forget(GameServer $server): void
    {
        $this->cache->deleteItem('vehicles.catalogue.'.$server->getId()->toRfc4122());
    }

    /** @param array<string, mixed> $held */
    private function stillCurrent(GameServer $server, array $held): bool
    {
        $session = $held['sessionId'] ?? null;

        if (!\is_string($session)) {
            return false;
        }

        return $session === $this->sessions->currentFor($server);
    }

    /** @return array<string, mixed> */
    private function fetch(GameServer $server): array
    {
        $empty = [
            'bodies' => [],
            'items' => [],
            'generatedAt' => null,
            'bridgeVersion' => null,
            'available' => false,
        ];

        $config = $server->getFtpConfig();

        if ($config === null) {
            return $empty;
        }

        try {
            $raw = $this->files->readTail($config, BridgeFiles::VEHICLE_CATALOGUE, self::MAX_BYTES);
        } catch (StorageException) {
            return $empty;
        }

        $payload = json_decode(trim($raw), true);

        if (!\is_array($payload) || !\is_array($payload['vehicles'] ?? null)) {
            return $empty;
        }

        $items = [];
        $bodies = [];

        foreach ($payload['vehicles'] as $vehicle) {
            if (!\is_array($vehicle) || !\is_string($vehicle['script'] ?? null)) {
                continue;
            }

            $described = $this->describe($vehicle['script']);
            $items[] = $described;

            $body = $described['body'];

            if (!isset($bodies[$body])) {
                $bodies[$body] = ['count' => 0, 'preview' => null];
            }

            ++$bodies[$body]['count'];

            // The first drawable member represents the body on its tile.
            if ($bodies[$body]['preview'] === null && $described['drawable']) {
                $bodies[$body]['preview'] = $described['script'];
            }
        }

        return [
            'bodies' => $this->summarise($bodies, $items),
            'items' => $items,
            'generatedAt' => isset($payload['generatedAt']) ? (int) $payload['generatedAt'] : null,
            'bridgeVersion' => isset($payload['bridgeVersion']) ? (string) $payload['bridgeVersion'] : null,
            'sessionId' => isset($payload['sessionId']) ? (string) $payload['sessionId'] : null,
            'available' => true,
        ];
    }

    /**
     * @return array{
     *     script: string,
     *     name: string,
     *     body: string,
     *     model: string|null,
     *     texture: string|null,
     *     drawable: bool
     * }
     */
    private function describe(string $script): array
    {
        $artwork = VehicleCatalogue::find($script);

        return [
            'script' => $script,
            'name' => VehicleNames::of($script),
            // A shared paint mask is one body shell. Anything without a
            // mask -- a burnt-out hull carries no paint -- stands alone.
            'body' => self::bodyOf($script, $artwork),
            'model' => $artwork['model'] ?? null,
            'texture' => $artwork['texture'] ?? null,
            'drawable' => $artwork !== null
                && $this->models->has($artwork['model'])
                && $artwork['texture'] !== null
                && $this->models->has($artwork['texture']),
        ];
    }

    /** Wrecks and burnt-out hulls, which carry no paint to group by. */
    public const WRECKS = 'wrecks';

    /**
     * Damage state, the no-random marker and the lights fitting name a
     * variant rather than a shell; "Normal" is a qualifier on the plainest
     * member. Stripping them leaves the shell a person recognises.
     */
    private const MODEL_VARIANTS = [
        'SmashedFront',
        'SmashedLeft',
        'SmashedRear',
        'SmashedRight',
        '_NoRandom',
        'Lights',
    ];

    /** @param array<string, mixed>|null $artwork */
    private static function bodyOf(string $script, ?array $artwork): string
    {
        $mask = $artwork['mask'] ?? null;

        if (!\is_string($mask)) {
            // Twenty unmasked entries would otherwise be twenty groups of
            // one. They are all wrecks, so they belong together.
            return self::WRECKS;
        }

        $body = pathinfo($mask, PATHINFO_FILENAME);
        $model = $artwork['model'] ?? null;

        // One mask covers two shells: vehicle_pickuptruck_mask paints both
        // the Chevalier D6 and the Dash Bulldriver, so the mask alone puts
        // 45 unrelated liveries in one group.
        return \is_string($model) ? $body.'.'.self::shellOf($model) : $body;
    }

    /** The shell a model belongs to, with its variant suffixes removed. */
    public static function shellOf(string $model): string
    {
        // The catalogue hands back a file name, not a bare model name.
        $bare = pathinfo($model, PATHINFO_FILENAME);
        $shell = preg_replace('/^Vehicles?_/', '', $bare) ?? $bare;

        do {
            $before = $shell;

            foreach (self::MODEL_VARIANTS as $suffix) {
                if (str_ends_with($shell, $suffix) && \strlen($shell) > \strlen($suffix)) {
                    $shell = substr($shell, 0, -\strlen($suffix));

                    break;
                }
            }
        } while ($shell !== $before);

        $shell = rtrim($shell, '_');

        if (str_ends_with($shell, 'Normal') && \strlen($shell) > 6) {
            $shell = substr($shell, 0, -6);
        }

        return $shell === '' ? $bare : $shell;
    }

    /**
     * @param array<string, array{count: int, preview: string|null}> $bodies
     * @param list<array<string, mixed>>                             $items
     *
     * @return list<array{id: string, name: string, count: int, preview: string|null}>
     */
    private function summarise(array $bodies, array $items): array
    {
        $summary = [];
        $names = [];

        foreach ($bodies as $id => $body) {
            $name = $id === self::WRECKS ? '' : self::nameBody($id, $items);
            $names[$name] = ($names[$name] ?? 0) + 1;

            $summary[] = [
                'id' => $id,
                'name' => $name,
                'count' => $body['count'],
                'preview' => $body['preview'],
            ];
        }

        // An empty name means "the frontend names this one", which is
        // how the wrecks group gets a translated label.
        // Two masks can share a model name -- the van and the van with
        // seats are both a Franklin Valuline -- so a duplicate keeps the
        // name of its plainest member as a qualifier.
        foreach ($summary as $index => $body) {
            if (($names[$body['name']] ?? 0) > 1 && $body['id'] !== self::WRECKS) {
                $summary[$index]['name'] = sprintf(
                    '%s — %s',
                    $body['name'],
                    self::describeBody($body['id']),
                );
            }
        }

        // Largest first: the van with 51 liveries is what an operator is
        // most likely to be looking for.
        usort($summary, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $summary;
    }

    /**
     * A readable qualifier for a body whose name is not unique: the shell
     * from "vehicle_van_mask.VanSeats" tells the two Valulines apart.
     */
    private static function describeBody(string $id): string
    {
        $shell = strrchr($id, '.');
        $part = $shell === false ? $id : substr($shell, 1);

        return trim(str_replace(['vehicle_', '_mask', '_'], ['', '', ' '], $part));
    }

    /**
     * A body is named after its plainest member, which is the one whose
     * script name is shortest -- "Van" over "VanMobileMechanics".
     *
     * @param list<array<string, mixed>> $items
     */
    private static function nameBody(string $id, array $items): string
    {
        $members = array_filter($items, static fn (array $i): bool => $i['body'] === $id);

        if ($members === []) {
            return $id;
        }

        usort(
            $members,
            static fn (array $a, array $b): int => \strlen((string) $a['script']) <=> \strlen((string) $b['script']),
        );

        return (string) $members[0]['name'];
    }
}
