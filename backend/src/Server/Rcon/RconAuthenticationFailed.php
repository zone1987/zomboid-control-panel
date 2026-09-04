<?php

declare(strict_types=1);

namespace App\Server\Rcon;

final class RconAuthenticationFailed extends RconException
{
    public function messageKey(): string
    {
        return 'rcon.authenticationFailed';
    }
}
