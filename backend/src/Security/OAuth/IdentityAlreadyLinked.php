<?php

declare(strict_types=1);

namespace App\Security\OAuth;

final class IdentityAlreadyLinked extends \RuntimeException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct(sprintf('This %s account is already linked to another user.', $provider));
    }
}
