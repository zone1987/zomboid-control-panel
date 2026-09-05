<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\ObjectStorageInterface;
use App\Storage\ObjectStorageProbe;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

/**
 * The store belongs to the operator, so a test object that fails to
 * come back out is litter in their bucket -- and a provider that
 * refuses a fraction of requests makes that the common case.
 */
final class ObjectStorageProbeTest extends TestCase
{
    public function testLeavesNothingBehindWhenItSucceeds(): void
    {
        $filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $probe = new ObjectStorageProbe($this->factoryReturning($filesystem));

        $result = $probe->run();

        self::assertTrue($result['ok']);
        self::assertSame([], $filesystem->listContents('', true)->toArray());
    }

    public function testTakesTheObjectBackOutWhenReadingFails(): void
    {
        $filesystem = new RefusingFilesystem(new InMemoryFilesystemAdapter(), refuse: 'read');
        $probe = new ObjectStorageProbe($this->factoryReturning($filesystem));

        $result = $probe->run();

        self::assertFalse($result['ok']);
        self::assertSame(
            [],
            $filesystem->listContents('', true)->toArray(),
            'The failed probe left its object in the bucket.',
        );
    }

    private function factoryReturning(Filesystem $filesystem): ObjectStorageInterface
    {
        return new class($filesystem) implements ObjectStorageInterface {
            public function __construct(private readonly Filesystem $filesystem)
            {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function bucket(): ?string
            {
                return 'test-bucket';
            }

            public function create(): Filesystem
            {
                return $this->filesystem;
            }

            public function client(): \AsyncAws\S3\S3Client
            {
                throw new \LogicException('The in-memory store needs no client.');
            }
        };
    }
}

/** A store that refuses one named operation, as the real one does at random. */
final class RefusingFilesystem extends Filesystem
{
    public function __construct(InMemoryFilesystemAdapter $adapter, private readonly string $refuse)
    {
        parent::__construct($adapter);
    }

    public function read(string $location): string
    {
        if ($this->refuse === 'read') {
            throw new \RuntimeException('AccessDenied');
        }

        return parent::read($location);
    }
}
