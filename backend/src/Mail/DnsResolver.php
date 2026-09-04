<?php

declare(strict_types=1);

namespace App\Mail;

interface DnsResolver
{
    /** @return list<string> */
    public function txt(string $name): array;
}
