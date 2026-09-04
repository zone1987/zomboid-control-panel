<?php

declare(strict_types=1);

namespace App\Server\Players;

final class BridgeUnavailable extends \RuntimeException
{
    public function __construct(
        private readonly string $messageKey,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($messageKey, 0, $previous);
    }

    public function messageKey(): string
    {
        return $this->messageKey;
    }
}
