<?php

declare(strict_types=1);

namespace App\Security\OAuth;

final class GoogleNotConfigured extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Google sign-in has no client credentials configured.');
    }
}
