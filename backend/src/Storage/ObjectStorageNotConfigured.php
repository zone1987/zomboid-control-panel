<?php

declare(strict_types=1);

namespace App\Storage;

final class ObjectStorageNotConfigured extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The object store is not configured.');
    }
}
