<?php

declare(strict_types=1);

namespace App\Server\Storage;

final class StorageException extends \RuntimeException
{
    public function __construct(
        private readonly string $messageKey,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function messageKey(): string
    {
        return $this->messageKey;
    }
}
