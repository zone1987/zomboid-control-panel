<?php

declare(strict_types=1);

namespace App\Account;

final class LastAdministrator extends \RuntimeException
{
    public function messageKey(): string
    {
        return 'users.lastAdministrator';
    }
}
