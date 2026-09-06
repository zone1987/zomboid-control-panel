<?php

declare(strict_types=1);

namespace App\Server\Vehicles;

use App\Entity\GameServer;

/**
 * The vehicles to draw, from both sources at once.
 *
 * Neither is sufficient alone. The save database has every vehicle the
 * world has generated but not its paint, which sits behind a part list
 * an external parser cannot safely skip. The bridge has the paint but
 * only for vehicles in chunks a player keeps loaded.
 *
 * So the database supplies the map and the bridge fills in what it can
 * see, matched by the id both of them carry.
 */
final readonly class VehicleOverlay
{
    public function __construct(
        private VehicleSourceInterface $saved,
        private VehicleSourceInterface $bridge,
    ) {
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     source: string,
     *     loaded: int
     * }
     */
    public function of(GameServer $server): array
    {
        $live = $this->liveById($server);
        $stored = $this->saved->vehicles($server);

        if ($stored === null) {
            // No database to read: everything the bridge sees is all
            // there is, which is honest rather than empty.
            return [
                'items' => array_values($live),
                'source' => $live === [] ? 'none' : 'bridge',
                'loaded' => \count($live),
            ];
        }

        $items = [];

        foreach ($stored as $vehicle) {
            $id = \is_int($vehicle['id'] ?? null) ? $vehicle['id'] : null;
            $items[] = $id !== null && isset($live[$id])
                ? self::merge($vehicle, $live[$id])
                : $vehicle;
        }

        return [
            'items' => $items,
            'source' => $live === [] ? 'saved' : 'both',
            'loaded' => \count($live),
        ];
    }

    /**
     * The bridge wins on everything it can see, because it reads the
     * live object while the database holds the last commit -- a moving
     * car is where the bridge says it is.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $live
     *
     * @return array<string, mixed>
     */
    private static function merge(array $stored, array $live): array
    {
        foreach (['x', 'y', 'z', 'engineRunning'] as $field) {
            if (isset($live[$field])) {
                $stored[$field] = $live[$field];
            }
        }

        foreach (['angle' => 'heading', 'fuel' => 'fuel'] as $from => $to) {
            if (($live[$from] ?? null) !== null) {
                $stored[$to] = $live[$from];
            }
        }

        // Paint and wear exist only here.
        foreach (['hue', 'saturation', 'value', 'rust', 'skin'] as $field) {
            if (($live[$field] ?? null) !== null) {
                $stored[$field] = $live[$field];
            }
        }

        $stored['live'] = true;

        return $stored;
    }

    /**
     * What the bridge currently sees, keyed by vehicle id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function liveById(GameServer $server): array
    {
        $vehicles = $this->bridge->vehicles($server);

        if ($vehicles === null) {
            return [];
        }

        $byId = [];

        foreach ($vehicles as $vehicle) {
            $byId[$vehicle['id']] = $vehicle;
        }

        return $byId;
    }
}
