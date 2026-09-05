<?php

declare(strict_types=1);

namespace App\Storage;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

/**
 * Proves the configured bucket actually works.
 *
 * Writing, reading back and deleting rather than only listing: a key
 * that may list but not write passes a read-only check and then fails
 * hours later, halfway through a render. The round trip is the only
 * thing that answers the question actually being asked.
 */
final readonly class ObjectStorageProbe
{
    private const PREFIX = 'zomboidcontrol-probe-';

    /** The store refuses a fraction of requests; one try is not enough. */
    private const CLEANUP_ATTEMPTS = 6;

    public function __construct(private ObjectStorageInterface $storage)
    {
    }

    /**
     * @return array{ok: bool, error?: string, detail?: string, bucket?: string}
     */
    public function run(): array
    {
        if (!$this->storage->isConfigured()) {
            return ['ok' => false, 'error' => 'settings.s3Incomplete'];
        }

        $key = self::PREFIX.bin2hex(random_bytes(8)).'.txt';
        $body = 'ZomboidControl connection test '.date(\DATE_ATOM);

        $filesystem = null;
        $written = false;

        try {
            $filesystem = $this->storage->create();
            $filesystem->write($key, $body);
            $written = true;
            $readBack = $filesystem->read($key);
            $filesystem->delete($key);
            $written = false;
        } catch (FilesystemException|\Throwable $exception) {
            // A probe that dies between writing and deleting would leave
            // its object in the operator's bucket, and a store that
            // refuses intermittently makes that the common case rather
            // than the rare one.
            $this->removeLeftover($filesystem, $key, $written);

            return [
                'ok' => false,
                'error' => 'settings.s3Rejected',
                // The provider's own words: "no such bucket" and "signature
                // does not match" need different fixes, and only the
                // message distinguishes them.
                'detail' => $this->summarise($exception),
            ];
        }

        if ($readBack !== $body) {
            return ['ok' => false, 'error' => 'settings.s3Mismatch'];
        }

        return ['ok' => true, 'bucket' => (string) $this->storage->bucket()];
    }

    /**
     * Takes the test object back out, whatever else went wrong.
     *
     * Silent by design: the caller is already reporting a failure, and
     * a second one about the cleanup would bury it.
     */
    private function removeLeftover(?FilesystemOperator $filesystem, string $key, bool $written): void
    {
        if ($filesystem === null || !$written) {
            return;
        }

        for ($attempt = 0; $attempt < self::CLEANUP_ATTEMPTS; ++$attempt) {
            try {
                $filesystem->delete($key);

                return;
            } catch (\Throwable) {
                usleep(200_000);
            }
        }
    }

    /**
     * The innermost message, trimmed.
     *
     * Flysystem wraps the provider's exception, so the outer message
     * says only that writing failed -- never why.
     */
    private function summarise(\Throwable $exception): string
    {
        $deepest = $exception;

        while ($deepest->getPrevious() !== null) {
            $deepest = $deepest->getPrevious();
        }

        $message = trim($deepest->getMessage());

        return mb_substr($message, 0, 300);
    }
}
