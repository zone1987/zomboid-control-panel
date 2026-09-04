<?php

declare(strict_types=1);

namespace App\Server\Bridge;

final class BridgeCommandFailed extends \RuntimeException
{
    public function __construct(string $message, public readonly string $key = 'bridge.commandFailed')
    {
        parent::__construct($message);
    }

    public function messageKey(): string
    {
        return $this->key;
    }
}
