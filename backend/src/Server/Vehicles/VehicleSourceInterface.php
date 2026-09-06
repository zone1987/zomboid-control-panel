<?php

declare(strict_types=1);

namespace App\Server\Vehicles;

use App\Entity\GameServer;

/**
 * Something that can say which vehicles a server has.
 *
 * Two implementations answer differently and are combined rather than
 * chosen between: the save database knows every vehicle but not its
 * paint, the bridge knows the paint of the ones currently loaded.
 */
interface VehicleSourceInterface
{
    /**
     * Null when this source cannot answer at all, which is different
     * from an empty list -- that means the world genuinely has none
     * here.
     *
     * @return list<array<string, mixed>>|null
     */
    public function vehicles(GameServer $server): ?array;
}
