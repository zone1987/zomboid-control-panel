<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Bridge;

use App\Entity\FtpConfig;
use App\Entity\GameServer;
use App\Server\Bridge\BridgeInstaller;
use App\Server\Bridge\BridgePathMissing;
use App\Server\Storage\FileBrowserInterface;
use PHPUnit\Framework\TestCase;

final class BridgeInstallerTest extends TestCase
{
    public function testRefusesAServerWithoutALuaPath(): void
    {
        $server = new GameServer('Test');
        new FtpConfig($server, 'host', 'user');

        $this->expectException(BridgePathMissing::class);
        $this->installer()->install($server);
    }

    public function testRefusesAServerWithoutTransferCredentials(): void
    {
        $this->expectException(BridgePathMissing::class);
        $this->installer()->install(new GameServer('Test'));
    }

    /**
     * The browser works relative to the base path, so an absolute Lua path
     * has to be reduced to the part below it — otherwise the upload lands
     * one directory tree too deep.
     */
    public function testReducesAnAbsolutePathToTheBase(): void
    {
        $browser = new RecordingFileBrowser();

        $this->installer($browser)->install($this->server('/home/zomboid', '/home/zomboid/media/lua/server'));

        self::assertSame('media/lua/server/ZomboidControlBridge.lua', $browser->path);
    }

    public function testAcceptsAPathAlreadyRelativeToTheBase(): void
    {
        $browser = new RecordingFileBrowser();

        $this->installer($browser)->install($this->server('/home/zomboid', 'media/lua/server'));

        self::assertSame('media/lua/server/ZomboidControlBridge.lua', $browser->path);
    }

    public function testHandlesARootBasePath(): void
    {
        $browser = new RecordingFileBrowser();

        $this->installer($browser)->install($this->server('/', '/media/lua/server'));

        self::assertSame('media/lua/server/ZomboidControlBridge.lua', $browser->path);
    }

    public function testUploadsTheBridgeSource(): void
    {
        $browser = new RecordingFileBrowser();

        $this->installer($browser)->install($this->server('/', 'media/lua/server'));

        self::assertStringContainsString('ZomboidControl', $browser->contents);
        self::assertStringContainsString('Events.OnTick.Add', $browser->contents);
    }

    public function testReadsTheVersionFromTheSource(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $this->installer()->version());
    }

    private function server(string $basePath, string $luaPath): GameServer
    {
        $server = new GameServer('Test');
        $config = new FtpConfig($server, 'host', 'user');
        $config->setBasePath($basePath);
        $config->setLuaServerPath($luaPath);

        return $server;
    }

    private function installer(?RecordingFileBrowser $browser = null): BridgeInstaller
    {
        return new BridgeInstaller(
            $browser ?? new RecordingFileBrowser(),
            __DIR__.'/../../../../resources/bridge/ZomboidControlBridge.lua',
        );
    }
}

/** Records what the installer asked for, without touching a network. */
final class RecordingFileBrowser implements FileBrowserInterface
{
    public ?string $path = null;
    public ?string $contents = null;

    /** @var array<string, string> */
    public array $uploads = [];

    public function listDirectory(FtpConfig $config, string $path = ''): array
    {
        return ['path' => $path, 'entries' => []];
    }

    public function verify(FtpConfig $config): array
    {
        return ['path' => $config->getBasePath(), 'entryCount' => 0, 'looksLikeZomboid' => false];
    }

    public function exists(FtpConfig $config, string $path): bool
    {
        return true;
    }

    public function upload(FtpConfig $config, string $path, string $contents): void
    {
        $this->uploads[$path] = $contents;

        if (str_ends_with($path, '.lua')) {
            $this->path = $path;
            $this->contents = $contents;
        }
    }
}
