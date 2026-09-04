<?php

declare(strict_types=1);

namespace App\Server\Rcon;

final class RconUnreachable extends RconException
{
    public function messageKey(): string
    {
        return 'rcon.unreachable';
    }
}
