<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Map;

use App\Server\Map\TileUploader;
use App\Storage\ObjectStorageInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The store this runs against refuses a fraction of requests with a
 * working key -- measured at 4 of 10 -- so an upload that gave up on
 * the first refusal would leave holes in a map of 1.5 million tiles.
 */
final class TileUploaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/tiles-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/layer0_files/16', 0o775, true);

        foreach (['0_0.jpg', '0_1.jpg', '1_0.jpg'] as $name) {
            file_put_contents($this->root.'/layer0_files/16/'.$name, str_repeat('x', 128));
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/layer0_files/16/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->root.'/layer0_files/16');
        @rmdir($this->root.'/layer0_files');
        @rmdir($this->root);
    }

    public function testGetsEveryTileThroughAStoreThatRefusesHalfTheTime(): void
    {
        $store = new FlakyFilesystem(new InMemoryFilesystemAdapter(), refuseEvery: 2);
        $uploader = new TileUploader($this->storageReturning($store), new NullLogger());

        $result = $uploader->upload($this->root, 'map/base');

        self::assertSame(3, $result['sent']);
        self::assertSame(0, $result['failed']);
        self::assertSame([], $uploader->verify($this->root, 'map/base'));
    }

    /** A tile that never made it must be named, not quietly counted. */
    public function testNamesWhatIsMissingFromTheStore(): void
    {
        $store = new Filesystem(new InMemoryFilesystemAdapter());
        $uploader = new TileUploader($this->storageReturning($store), new NullLogger());

        $uploader->upload($this->root, 'map/base');
        $store->delete('map/base/layer0_files/16/0_1.jpg');

        self::assertSame(
            ['map/base/layer0_files/16/0_1.jpg'],
            $uploader->verify($this->root, 'map/base'),
        );
    }

    /** A tile that is already up there is not sent twice. */
    public function testSkipsWhatIsAlreadyInTheStore(): void
    {
        $store = new Filesystem(new InMemoryFilesystemAdapter());
        $uploader = new TileUploader($this->storageReturning($store), new NullLogger());

        $uploader->upload($this->root, 'map/base');
        $second = $uploader->upload($this->root, 'map/base');

        self::assertSame(0, $second['sent']);
        self::assertSame(3, $second['skipped']);
    }

    /**
     * A world changes: players build and demolish, and a re-render
     * produces different bytes for the same position. Skipping on
     * existence alone would freeze the map at its first render.
     */
    public function testReplacesATileWhoseContentChanged(): void
    {
        $store = new Filesystem(new InMemoryFilesystemAdapter());
        $uploader = new TileUploader($this->storageReturning($store), new NullLogger());

        $uploader->upload($this->root, 'map/base');

        file_put_contents($this->root.'/layer0_files/16/0_0.jpg', str_repeat('y', 512));
        $second = $uploader->upload($this->root, 'map/base');

        self::assertSame(1, $second['sent'], 'The changed tile was not sent again.');
        self::assertSame(2, $second['skipped']);
        self::assertSame(512, $store->fileSize('map/base/layer0_files/16/0_0.jpg'));
    }

    /**
     * Where a building was demolished in-game the new render draws
     * nothing at all, so its old tile would be served for ever unless
     * something notices it is no longer produced.
     */
    public function testRemovesATileThisRenderNoLongerProduces(): void
    {
        $store = new Filesystem(new InMemoryFilesystemAdapter());
        $uploader = new TileUploader($this->storageReturning($store), new NullLogger());

        $first = $uploader->upload($this->root, 'map/base');

        // The world changed: one cell now draws nothing.
        unlink($this->root.'/layer0_files/16/1_0.jpg');
        $second = $uploader->upload($this->root, 'map/base');

        $swept = $uploader->removeStale('map/base', $second['keys']);

        self::assertSame(1, $swept['removed']);
        self::assertSame(2, $swept['kept']);
        self::assertFalse($store->fileExists('map/base/layer0_files/16/1_0.jpg'));
        self::assertTrue($store->fileExists('map/base/layer0_files/16/0_0.jpg'));
        self::assertCount(3, $first['keys']);
    }

    /** The viewer needs the descriptors, which belong to no cell. */
    public function testKeepsDescriptorsAndMetadata(): void
    {
        $store = new Filesystem(new InMemoryFilesystemAdapter());
        $uploader = new TileUploader($this->storageReturning($store), new NullLogger());

        $store->write('map/base/layer0.dzi', '<Image/>');
        $store->write('map/base/map_info.json', '{}');

        $result = $uploader->upload($this->root, 'map/base');
        $swept = $uploader->removeStale('map/base', $result['keys']);

        self::assertSame(0, $swept['removed']);
        self::assertTrue($store->fileExists('map/base/layer0.dzi'));
        self::assertTrue($store->fileExists('map/base/map_info.json'));
    }

    /**
     * Two tiles can be the same length and different pictures, so the
     * cheap size check alone would leave a changed tile behind.
     */
    public function testReplacesATileThatChangedWithoutChangingSize(): void
    {
        $store = new Filesystem(new InMemoryFilesystemAdapter());
        $uploader = new TileUploader($this->storageReturning($store), new NullLogger());

        $uploader->upload($this->root, 'map/base');

        // Same 128 bytes, different picture.
        file_put_contents($this->root.'/layer0_files/16/0_0.jpg', str_repeat('z', 128));
        $second = $uploader->upload($this->root, 'map/base');

        self::assertSame(1, $second['sent'], 'A tile of unchanged size was never re-sent.');
        self::assertSame(str_repeat('z', 128), $store->read('map/base/layer0_files/16/0_0.jpg'));
    }

    /** Nothing changed, so nothing is sent: a re-run costs no traffic. */
    public function testSendsNothingWhenTheWorldIsUnchanged(): void
    {
        $store = new Filesystem(new InMemoryFilesystemAdapter());
        $uploader = new TileUploader($this->storageReturning($store), new NullLogger());

        $uploader->upload($this->root, 'map/base');
        $second = $uploader->upload($this->root, 'map/base');

        self::assertSame(0, $second['sent']);
        self::assertSame(3, $second['skipped']);
        self::assertSame(0, $second['bytes']);
    }

    /**
     * A second pass over the same directory reports its tiles as
     * skipped, not sent. Counting those bytes again is how 1.6 GB in
     * the store came out as 11.9 in the interface.
     */
    public function testASecondPassCountsNoBytesAgain(): void
    {
        $store = new Filesystem(new InMemoryFilesystemAdapter());
        $uploader = new TileUploader($this->storageReturning($store), new NullLogger());

        $first = $uploader->upload($this->root, 'map/base');
        $second = $uploader->upload($this->root, 'map/base');

        self::assertSame(3 * 128, $first['bytes']);
        self::assertSame(0, $second['bytes'], 'The same tiles were billed twice.');
    }

    /**
     * What the operator is billed for is what the prefix holds, not
     * what this run happened to send: a resumed run sends almost
     * nothing while the bucket still holds everything.
     */
    public function testReportsWhatThePrefixHolds(): void
    {
        $store = new Filesystem(new InMemoryFilesystemAdapter());
        $store->write('map/base/layer0_files/16/9_9.jpg', str_repeat('o', 500));

        $uploader = new TileUploader($this->storageReturning($store), new NullLogger());
        $result = $uploader->upload($this->root, 'map/base');

        self::assertSame(3 * 128 + 500, $result['bytesHeld']);
        self::assertSame(4, $result['objectsHeld']);
        self::assertSame(3 * 128, $result['bytes'], 'Only what was sent counts as sent.');
    }

    /**
     * Listing costs a request per thousand objects, so a run past a
     * hundred thousand tiles would spend longer asking what is there
     * than writing to it.
     */
    public function testDoesNotListTheStoreWhenHandedAListing(): void
    {
        $store = new CountingFilesystem(new InMemoryFilesystemAdapter());
        $uploader = new TileUploader($this->storageReturning($store), new NullLogger());

        $first = $uploader->upload($this->root, 'map/base');
        self::assertSame(1, $store->listings, 'The first batch has to ask.');

        $uploader->upload($this->root, 'map/base', null, $first['inStore']);
        self::assertSame(1, $store->listings, 'The second must not.');
    }

    /** What a batch wrote has to appear in the listing it hands on. */
    public function testCarriesWhatItWroteIntoTheListing(): void
    {
        $store = new CountingFilesystem(new InMemoryFilesystemAdapter());
        $uploader = new TileUploader($this->storageReturning($store), new NullLogger());

        $result = $uploader->upload($this->root, 'map/base');

        self::assertArrayHasKey('map/base/layer0_files/16/0_0.jpg', $result['inStore']);
        self::assertSame(128, $result['inStore']['map/base/layer0_files/16/0_0.jpg']);
    }

    private function storageReturning(FilesystemOperator $filesystem): ObjectStorageInterface
    {
        return new class($filesystem) implements ObjectStorageInterface {
            public function __construct(private readonly FilesystemOperator $filesystem)
            {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function bucket(): ?string
            {
                return 'test';
            }

            public function create(): FilesystemOperator
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

/** Refuses every nth call, as the real store does at random. */
final class FlakyFilesystem extends Filesystem
{
    private int $calls = 0;

    public function __construct(InMemoryFilesystemAdapter $adapter, private readonly int $refuseEvery)
    {
        parent::__construct($adapter);
    }

    public function writeStream(string $location, $contents, array $config = []): void
    {
        if (++$this->calls % $this->refuseEvery === 0) {
            throw new \RuntimeException('AccessDenied');
        }

        parent::writeStream($location, $contents, $config);
    }
}

/** Counts how often the bucket is listed. */
final class CountingFilesystem extends Filesystem
{
    public int $listings = 0;

    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): \League\Flysystem\DirectoryListing
    {
        ++$this->listings;

        return parent::listContents($location, $deep);
    }
}
