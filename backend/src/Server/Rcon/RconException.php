<?php

declare(strict_types=1);

namespace App\Server\Rcon;

abstract class RconException extends \RuntimeException
{
    /** Translation key for the interface. */
    abstract public function messageKey(): string;
}
