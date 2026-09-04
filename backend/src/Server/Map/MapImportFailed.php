<?php

declare(strict_types=1);

namespace App\Server\Map;

final class MapImportFailed extends \RuntimeException
{
    public function __construct(string $message, private readonly string $key = 'map.importFailed')
    {
        parent::__construct($message);
    }

    public function messageKey(): string
    {
        return $this->key;
    }
}
