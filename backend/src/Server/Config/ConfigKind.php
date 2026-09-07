<?php

declare(strict_types=1);

namespace App\Server\Config;

/** Which of a server's two settings files is meant. */
enum ConfigKind: string
{
    case Sandbox = 'sandbox';
    case Ini = 'ini';
}
