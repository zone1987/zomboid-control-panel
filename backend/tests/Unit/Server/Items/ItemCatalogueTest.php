<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Items;

use App\Entity\FtpConfig;
use App\Entity\GameServer;
use App\Server\Bridge\ServerSession;
use App\Server\Items\ItemCatalogue;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The catalogue is a megabyte and only changes when the server restarts,
 * so what matters here is how rarely it is fetched.
 */
final class ItemCatalogueTest extends TestCase
{
    public function testFetchesTheCatalogueOnce(): void
    {
        $files = new StubFileBrowser($this->payload('session-1'));
        $catalogue = $this->catalogue($files);
        $server = $this->server();

        $catalogue->forServer($server);
        $catalogue->forServer($server);
        $catalogue->forServer($server);

        self::assertSame(1, $files->catalogueReads, 'the catalogue is fetched once');
    }

    /** A restart reloads everything the server knows, mods included. */
    public function testFetchesAgainWhenTheServerHasRestarted(): void
    {
        $files = new StubFileBrowser($this->payload('session-1'));
        $catalogue = $this->catalogue($files);
        $server = $this->server();

        $catalogue->forServer($server);

        $files->contents = $this->payload('session-2');
        $files->sessionId = 'session-2';
        $result = $catalogue->forServer($server);

        self::assertSame(2, $files->catalogueReads);
        self::assertSame('session-2', $result['sessionId']);
    }

    public function testFetchesAgainWhenAskedToRefresh(): void
    {
        $files = new StubFileBrowser($this->payload('session-1'));
        $catalogue = $this->catalogue($files);
        $server = $this->server();

        $catalogue->forServer($server);
        $catalogue->forServer($server, refresh: true);

        self::assertSame(2, $files->catalogueReads);
    }

    public function testReadsTheItems(): void
    {
        $result = $this->catalogue(new StubFileBrowser($this->payload('s')))->forServer($this->server());

        self::assertTrue($result['available']);
        self::assertCount(2, $result['items']);
        self::assertSame('Base.Axe', $result['items'][0]['type']);
    }

    /**
     * A momentary failure must not throw away a good catalogue: the
     * server is probably just restarting, and stale items beat none.
     */
    public function testKeepsWhatItHasWhenTheServerGoesAway(): void
    {
        $files = new StubFileBrowser($this->payload('session-1'));
        $catalogue = $this->catalogue($files);
        $server = $this->server();

        $catalogue->forServer($server);

        $files->failEverything = true;
        $result = $catalogue->forServer($server, refresh: true);

        self::assertTrue($result['available']);
        self::assertCount(2, $result['items']);
    }

    public function testReportsNothingWhenThereIsNoCatalogueAtAll(): void
    {
        $files = new StubFileBrowser('');
        $files->failEverything = true;

        $result = $this->catalogue($files)->forServer($this->server());

        self::assertFalse($result['available']);
        self::assertSame([], $result['items']);
    }

    public function testHandlesAFileThatIsNotJson(): void
    {
        $result = $this->catalogue(new StubFileBrowser('not json'))->forServer($this->server());

        self::assertFalse($result['available']);
    }

    private function payload(string $sessionId): string
    {
        return json_encode([
            'bridgeVersion' => '0.7.0',
            'sessionId' => $sessionId,
            'generatedAt' => 1788549843,
            'items' => [
                ['type' => 'Base.Axe', 'name' => 'Axe'],
                ['type' => 'Base.Nails', 'name' => 'Nails'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function catalogue(StubFileBrowser $files): ItemCatalogue
    {
        $cache = new ArrayAdapter();

        return new ItemCatalogue($files, new ServerSession($files, $cache), $cache);
    }

    private function server(): GameServer
    {
        $server = new GameServer('Test');
        new FtpConfig($server, 'host', 'user');

        return $server;
    }
}

final class StubFileBrowser implements FileBrowserInterface
{
    public int $catalogueReads = 0;
    public bool $failEverything = false;
    public string $sessionId = 'session-1';

    public function __construct(public string $contents)
    {
    }

    public function readTail(FtpConfig $config, string $path, int $maxBytes = 65536): string
    {
        if ($this->failEverything) {
            throw new StorageException('errors.unreachable', 'Unreachable.');
        }

        if (str_ends_with($path, 'players.json')) {
            return json_encode(['sessionId' => $this->sessionId], JSON_THROW_ON_ERROR);
        }

        ++$this->catalogueReads;

        return $this->contents;
    }

    public function listDirectory(FtpConfig $config, string $path = ''): array
    {
        return ['path' => $path, 'entries' => []];
    }

    public function verify(FtpConfig $config): array
    {
        return ['path' => '/', 'entryCount' => 0, 'looksLikeZomboid' => false];
    }

    public function directoryExists(FtpConfig $config, string $path): bool
    {
        return true;
    }

    public function fileExists(FtpConfig $config, string $path): bool
    {
        return true;
    }

    public function upload(FtpConfig $config, string $path, string $contents): void
    {
    }
}
