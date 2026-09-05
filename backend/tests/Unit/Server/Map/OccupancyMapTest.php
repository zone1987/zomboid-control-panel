<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Map;

use App\Server\Map\CellOccupancy;
use App\Server\Map\OccupancyMap;
use App\Storage\ObjectStorageInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Surveying the world costs a quarter of an hour of FTP. Keeping the
 * answer means a second run starts drawing straight away, so it has to
 * come back exactly as it went in.
 */
final class OccupancyMapTest extends TestCase
{
    private Filesystem $store;

    protected function setUp(): void
    {
        $this->store = new Filesystem(new InMemoryFilesystemAdapter());
    }

    public function testGivesBackTheFloorsItWasHanded(): void
    {
        $map = $this->map();
        $map->record(['4,38' => $this->cell([0 => 1024, 1 => 4, 2 => 35])]);

        $read = $this->map()->all();

        self::assertSame([0, 1, 2], $read['4,38']->floors());
        self::assertSame(1024, $read['4,38']->blocksOn(0));
        self::assertSame(4, $read['4,38']->blocksOn(1));
    }

    /** A floor with nothing on it must not come back as work to do. */
    public function testDropsAFloorWithNoOccupiedBlock(): void
    {
        $this->map()->record(['4,39' => $this->cell([0 => 1024])]);

        $read = $this->map()->all();

        self::assertSame([0], $read['4,39']->floors());
        self::assertFalse($read['4,39']->hasContent(1));
    }

    /** The mask says where, not just how many. */
    public function testKeepsTheBlockLayout(): void
    {
        $original = $this->cell([2 => 3], marked: [0, 5, 1023]);
        $this->map()->record(['4,38' => $original]);

        $read = $this->map()->all()['4,38'];

        self::assertTrue($read->blockHasContent(2, 0, 0), 'block 0');
        self::assertTrue($read->blockHasContent(2, 0, 5), 'block 5');
        self::assertTrue($read->blockHasContent(2, 31, 31), 'block 1023');
        self::assertFalse($read->blockHasContent(2, 0, 1), 'block 1 was never marked');
    }

    public function testAddsToWhatIsAlreadyThere(): void
    {
        $this->map()->record(['4,38' => $this->cell([0 => 1024])]);
        $this->map()->record(['4,39' => $this->cell([0 => 1024])]);

        self::assertCount(2, $this->map()->all());
    }

    public function testSaysNothingWhenNoneWasEverWritten(): void
    {
        self::assertSame([], $this->map()->all());
    }

    public function testForgetsOnRequest(): void
    {
        $map = $this->map();
        $map->record(['4,38' => $this->cell([0 => 1024])]);
        $map->forget();

        self::assertSame([], $this->map()->all());
    }

    /**
     * @param array<int, int> $blocks floor => occupied count
     * @param list<int>|null  $marked which block indices carry content
     */
    private function cell(array $blocks, ?array $marked = null): CellOccupancy
    {
        $floors = [];

        foreach ($blocks as $floor => $count) {
            $bits = str_repeat("\0", 128);
            $indices = $marked ?? range(0, $count - 1);

            foreach ($indices as $index) {
                $bits[$index >> 3] = \chr(\ord($bits[$index >> 3]) | (1 << ($index & 7)));
            }

            $floors[(string) $floor] = [
                'blocks' => $count,
                'total' => 1024,
                'mask' => base64_encode($bits),
            ];
        }

        return CellOccupancy::fromSurvey([
            'minlayer' => min(array_keys($blocks)),
            'maxlayer' => max(array_keys($blocks)) + 1,
            'floors' => $floors,
        ]);
    }

    private function map(): OccupancyMap
    {
        return new OccupancyMap($this->storageReturning($this->store), new NullLogger());
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
