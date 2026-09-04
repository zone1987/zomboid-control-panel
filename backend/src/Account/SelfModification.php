<?php

declare(strict_types=1);

namespace App\Account;

final class SelfModification extends \RuntimeException
{
    public function __construct(private readonly string $key)
    {
        parent::__construct($key);
    }

    public function messageKey(): string
    {
        return $this->key;
    }
}
