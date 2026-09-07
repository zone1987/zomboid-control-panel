<?php

declare(strict_types=1);

namespace App\Server\Discord;

/**
 * Discord refused a request, or could not be reached.
 *
 * Carries a message key so the interface can say which, rather than
 * showing an HTTP status to an operator.
 */
class DiscordException extends \RuntimeException
{
    public function __construct(
        private readonly string $messageKey,
        string $detail = '',
        private readonly ?int $status = null,
    ) {
        parent::__construct($detail === '' ? $messageKey : $messageKey.': '.$detail);
    }

    public function messageKey(): string
    {
        return $this->messageKey;
    }

    public function status(): ?int
    {
        return $this->status;
    }
}
