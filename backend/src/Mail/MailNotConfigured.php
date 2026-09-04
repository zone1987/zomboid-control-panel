<?php

declare(strict_types=1);

namespace App\Mail;

final class MailNotConfigured extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No SMTP account is configured; mail cannot be sent.');
    }
}
