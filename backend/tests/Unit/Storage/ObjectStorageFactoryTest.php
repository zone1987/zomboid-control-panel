<?php

declare(strict_types=1);

namespace App\Tests\Unit\Storage;

use App\Storage\ObjectStorageFactory;
use PHPUnit\Framework\TestCase;

/**
 * An operator copies the endpoint out of their provider's console, and
 * what is printed there differs: Hetzner shows a bare host, its own
 * documentation an https URL. Both have to work, because the error
 * AsyncAws gives for a bare host -- "the endpoint is invalid" -- names
 * neither the cause nor the fix.
 */
final class ObjectStorageFactoryTest extends TestCase
{
    public function testAddsTheSchemeToABareHost(): void
    {
        self::assertSame(
            'https://nbg1.your-objectstorage.com',
            ObjectStorageFactory::normaliseEndpoint('nbg1.your-objectstorage.com'),
        );
    }

    public function testKeepsAnEndpointThatAlreadyHasOne(): void
    {
        self::assertSame(
            'https://nbg1.your-objectstorage.com',
            ObjectStorageFactory::normaliseEndpoint('https://nbg1.your-objectstorage.com'),
        );
    }

    /** A local MinIO is reached over plain http; forcing https breaks it. */
    public function testLeavesPlainHttpAlone(): void
    {
        self::assertSame('http://minio:9000', ObjectStorageFactory::normaliseEndpoint('http://minio:9000'));
    }

    public function testTrimsSurroundingSpaceAndATrailingSlash(): void
    {
        self::assertSame(
            'https://nbg1.your-objectstorage.com',
            ObjectStorageFactory::normaliseEndpoint('  https://nbg1.your-objectstorage.com/  '),
        );
    }

    public function testLeavesAnEmptyValueEmpty(): void
    {
        self::assertSame('', ObjectStorageFactory::normaliseEndpoint('   '));
    }
}
