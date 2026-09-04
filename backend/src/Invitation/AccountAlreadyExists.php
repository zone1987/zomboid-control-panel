<?php

declare(strict_types=1);

namespace App\Invitation;

final class AccountAlreadyExists extends \RuntimeException
{
    public function __construct(public readonly string $email)
    {
        parent::__construct(sprintf('An account already exists for "%s".', $email));
    }
}
