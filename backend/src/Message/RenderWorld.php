<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/** Render every cell of a server's world and put the tiles in the store. */
final readonly class RenderWorld
{
    public function __construct(
        public Uuid $serverId,
        public bool $upload = true,
        /** Empty the store's map prefix first, so no stale tiles survive. */
        public bool $fresh = false,
    ) {
    }
}
