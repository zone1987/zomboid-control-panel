<?php

declare(strict_types=1);

namespace App\Server\Items\Icons;

final class UploadRefused extends \RuntimeException
{
    public function __construct(private readonly string $messageKey)
    {
        parent::__construct($messageKey);
    }

    public function messageKey(): string
    {
        return $this->messageKey;
    }
}
