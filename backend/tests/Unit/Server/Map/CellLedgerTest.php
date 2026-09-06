<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Map;

use App\Server\Map\CellLedger;
use App\Storage\ObjectStorageInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The ledger decides what a second run may skip.
 *
 * That is right while the geometry holds and wrong the moment it
 * changes: the cell is unchanged, but the tiles standing for it belong
 * to a pyramid that no longer exists.
 */
final class CellLedgerTest extends TestCase
{
    public function testSkipsACellWhoseDataIsUnchanged(): void
    {
        $ledger = $this->ledgerOn(new Filesystem(new InMemoryFilesystemAdapter()));

        $ledger->record([CellLedger::name(47, 27, 0) => 'abc']);

        self::assertTrue($ledger->matches(47, 27, 'abc', 0));
        self::assertFalse($ledger->matches(47, 27, 'different', 0));
    }

    /** Each floor is drawn in its own pass, so each is keyed apart. */
    public function testTellsTheFloorsApart(): void
    {
        $ledger = $this->ledgerOn(new Filesystem(new InMemoryFilesystemAdapter()));

        $ledger->record([CellLedger::name(47, 27, 0) => 'abc']);

        self::assertFalse($ledger->matches(47, 27, 'abc', 1));
    }

    /**
     * A run starting afresh must draw the world again, whatever the
     * cells look like -- otherwise a geometry change leaves the map
     * built from two incompatible renders.
     */
    public function testForgetsEverythingWhenStartingAfresh(): void
    {
        $store = new Filesystem(new InMemoryFilesystemAdapter());
        $ledger = $this->ledgerOn($store);

        $ledger->record([CellLedger::name(47, 27, 0) => 'abc']);

        self::assertTrue($store->fileExists('map/cells.json'));

        $ledger->clear();

        self::assertFalse($ledger->matches(47, 27, 'abc', 0));
        // Gone from the store too, not only from the cache: the next
        // run builds a fresh instance and would read the old file.
        self::assertFalse($store->fileExists('map/cells.json'));
    }

    /** Clearing an empty ledger is a normal first run, not a fault. */
    public function testClearingWhenNothingWasRecordedIsHarmless(): void
    {
        $ledger = $this->ledgerOn(new Filesystem(new InMemoryFilesystemAdapter()));

        $ledger->clear();

        self::assertFalse($ledger->matches(47, 27, 'abc', 0));
    }

    private function ledgerOn(FilesystemOperator $filesystem): CellLedger
    {
        return new CellLedger($this->storageReturning($filesystem), new NullLogger());
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
