<?php

declare(strict_types=1);

namespace App\Server\Vehicles;

use App\Entity\GameServer;
use App\Server\Bridge\ServerInfoReader;

/**
 * The vehicles the bridge can see, as a vehicle source.
 *
 * ServerInfoReader reads the whole slow-moving world state; this is
 * only its vehicle half, so the overlay can treat both sources alike.
 */
final readonly class BridgeVehicleSource implements VehicleSourceInterface
{
    public function __construct(private ServerInfoReader $info)
    {
    }

    public function vehicles(GameServer $server): ?array
    {
        return $this->info->vehicles($server);
    }
}
