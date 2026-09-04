<?php

declare(strict_types=1);

namespace App\Server\Rcon;

final class RconCommandFailed extends RconException
{
    public function messageKey(): string
    {
        return 'rcon.commandFailed';
    }
}
