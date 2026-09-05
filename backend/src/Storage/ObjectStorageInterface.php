<?php

declare(strict_types=1);

namespace App\Storage;

use AsyncAws\S3\S3Client;
use League\Flysystem\FilesystemOperator;

/**
 * The object store, as everything but the factory sees it.
 *
 * Exists so a test can hand the probe an in-memory store and check
 * that a failed round trip leaves nothing behind -- which is the
 * operator's bucket, and their storage bill.
 */
interface ObjectStorageInterface
{
    public function isConfigured(): bool;

    public function bucket(): ?string;

    /** @throws ObjectStorageNotConfigured */
    public function create(): FilesystemOperator;

    /** The raw client, for work Flysystem cannot express. */
    public function client(): S3Client;
}
