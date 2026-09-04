<?php

declare(strict_types=1);

namespace App\Server\Bridge;

final class BridgePathMissing extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The server has no media/lua/server path configured.');
    }
}
