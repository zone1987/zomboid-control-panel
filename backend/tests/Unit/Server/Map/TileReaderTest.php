<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Map;

use App\Server\Map\IsometricTiles;
use App\Server\Map\TileReader;
use App\Storage\ObjectStorageInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A finished render is 330 GB in the object store and nothing on disk,
 * because every tile is deleted once the store confirms it. Reading
 * only locally would leave the map blank after a successful run.
 */
final class TileReaderTest extends TestCase
{
    private string $root;
    private Filesystem $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/reader-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        $this->store = new Filesystem(new InMemoryFilesystemAdapter());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->root);
    }

    public function testReadsATileTheStoreHoldsAndDiskDoesNot(): void
    {
        $this->store->write('map/base/layer0_files/16/3_4.jpg', 'tile bytes');

        $stream = $this->reader()->stream('layer0_files/16/3_4.jpg');

        self::assertNotNull($stream);
        self::assertSame('tile bytes', stream_get_contents($stream));
        fclose($stream);
    }

    public function testReportsATileNobodyHas(): void
    {
        self::assertNull($this->reader()->stream('layer0_files/16/9_9.jpg'));
    }

    /** The path arrives from a URL, so it must not escape the prefix. */
    public function testRefusesAPathThatClimbsOut(): void
    {
        self::assertNull($this->reader()->stream('../../../etc/passwd'));
        self::assertNull($this->reader()->stream('layer0_files/../../secret'));
    }

    /**
     * Geometry is read on every request; fetching a few hundred bytes
     * over the network each time would be absurd.
     */
    public function testCopiesTheDescriptorsDownOnFirstUse(): void
    {
        $this->store->write('map/base/map_info.json', json_encode([
            'w' => 2314688, 'h' => 1021920, 'sqr' => 128, 'x0' => 1, 'y0' => 2,
            'minlayer' => -1, 'maxlayer' => 1,
        ]));
        $this->store->write('map/base/layer-1.dzi', '<Image TileSize="1024"/>');
        $this->store->write('map/base/layer0.dzi', '<Image TileSize="1024"/>');

        $tiles = new IsometricTiles($this->root);

        self::assertFalse($tiles->isAvailable());
        self::assertTrue($this->reader($tiles)->hasRender());
        self::assertTrue($tiles->isAvailable());
        self::assertSame([-1, 0], $tiles->levels());
    }

    public function testSaysThereIsNoRenderWhenNeitherPlaceHasOne(): void
    {
        self::assertFalse($this->reader()->hasRender());
    }

    private function reader(?IsometricTiles $tiles = null): TileReader
    {
        return new TileReader(
            $tiles ?? new IsometricTiles($this->root),
            $this->storageReturning($this->store),
            new NullLogger(),
        );
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
