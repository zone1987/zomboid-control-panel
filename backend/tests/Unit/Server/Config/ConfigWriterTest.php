<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Config;

use App\Entity\FtpConfig;
use App\Entity\GameServer;
use App\Server\Config\ConfigBackup;
use App\Server\Config\ConfigKind;
use App\Server\Config\ConfigWriteRefused;
use App\Server\Config\ConfigWriter;
use App\Server\Config\IniWriter;
use App\Server\Config\LuaTableReader;
use App\Server\Config\LuaTableWriter;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use PHPUnit\Framework\TestCase;

/**
 * Saving a settings file: what it changes, and what it refuses.
 *
 * A wrong write here stops a real server booting, so the refusals matter
 * as much as the successes.
 */
final class ConfigWriterTest extends TestCase
{
    private const INI = "PVP=true\nMaxPlayers=32\nSomeModKey=7\n";
    private const SANDBOX = "SandboxVars = {\n    Zombies = 4,\n    ModThing = 9,\n}\n";

    public function testWritesTheChangedValueAndNothingElse(): void
    {
        $files = new RecordingBrowser(self::INI);

        $result = $this->writer($files)->apply($this->config(), 'Server/x.ini', ConfigKind::Ini, [
            'MaxPlayers' => 16,
        ]);

        self::assertSame(['MaxPlayers'], $result['written']);
        self::assertTrue($result['verified']);
        self::assertStringContainsString('MaxPlayers=16', $files->contents);
        self::assertStringContainsString('PVP=true', $files->contents);
        self::assertStringContainsString('SomeModKey=7', $files->contents, "a mod's value survives");
    }

    /**
     * The user's requirement: settings are editable, never removable.
     * A key that is not in the file would be an addition, so it is
     * refused rather than appended.
     */
    public function testRefusesAKeyTheFileDoesNotHold(): void
    {
        $this->expectException(ConfigWriteRefused::class);
        $this->expectExceptionMessageMatches('/unknownKeys.*Invented/');

        $this->writer(new RecordingBrowser(self::INI))
            ->apply($this->config(), 'Server/x.ini', ConfigKind::Ini, ['Invented' => 1]);
    }

    /** Omitting a key means leaving it alone, never deleting it. */
    public function testAKeyLeftOutOfTheRequestIsUntouched(): void
    {
        $files = new RecordingBrowser(self::INI);

        $this->writer($files)->apply($this->config(), 'Server/x.ini', ConfigKind::Ini, ['PVP' => false]);

        self::assertStringContainsString('MaxPlayers=32', $files->contents);
        self::assertStringContainsString('SomeModKey=7', $files->contents);
        self::assertSame(
            substr_count(self::INI, "\n"),
            substr_count($files->contents, "\n"),
            'no line was added or removed',
        );
    }

    public function testBacksUpBeforeWriting(): void
    {
        $files = new RecordingBrowser(self::INI);

        $result = $this->writer($files)->apply($this->config(), 'Server/x.ini', ConfigKind::Ini, [
            'PVP' => false,
        ]);

        self::assertSame('backed-up', $result['backup']['state']);
        self::assertNotNull($result['backup']['path']);
        self::assertSame(self::INI, $files->backups[$result['backup']['path']] ?? null);
    }

    /** Writing what is already there costs a backup for nothing. */
    public function testDoesNothingWhenTheValueIsAlreadyWhatWasAsked(): void
    {
        $files = new RecordingBrowser(self::INI);

        $result = $this->writer($files)->apply($this->config(), 'Server/x.ini', ConfigKind::Ini, [
            'MaxPlayers' => 32,
        ]);

        self::assertSame([], $result['written']);
        self::assertTrue($result['verified']);
        self::assertSame([], $files->backups, 'no backup for a write that did not happen');
    }

    public function testRefusesAnEmptyRequest(): void
    {
        $this->expectException(ConfigWriteRefused::class);

        $this->writer(new RecordingBrowser(self::INI))
            ->apply($this->config(), 'Server/x.ini', ConfigKind::Ini, []);
    }

    /**
     * The read-back is the point: a server that rewrites the file, or a
     * transfer that silently truncates, must not read as success.
     */
    public function testRestoresTheBackupWhenTheReadBackDisagrees(): void
    {
        $files = new RecordingBrowser(self::INI, rewriteTo: "PVP=true\nMaxPlayers=99\nSomeModKey=7\n");

        $result = $this->writer($files)->apply($this->config(), 'Server/x.ini', ConfigKind::Ini, [
            'MaxPlayers' => 16,
        ]);

        self::assertFalse($result['verified']);
        self::assertSame(['MaxPlayers'], $result['mismatched']);
        self::assertTrue($result['restored']);
        self::assertSame(self::INI, $files->contents, 'the file is back as it was');
    }

    public function testWritesASandboxValueThroughTheLuaWriter(): void
    {
        $files = new RecordingBrowser(self::SANDBOX);

        $result = $this->writer($files)->apply($this->config(), 'Server/x.lua', ConfigKind::Sandbox, [
            'Zombies' => 2,
        ]);

        self::assertSame(['Zombies'], $result['written']);
        self::assertStringContainsString('Zombies = 2', $files->contents);
        self::assertStringContainsString('ModThing = 9', $files->contents);
    }

    private function writer(FileBrowserInterface $files): ConfigWriter
    {
        return new ConfigWriter(
            $files,
            new ConfigBackup($files),
            new LuaTableReader(),
            new LuaTableWriter(),
            new IniWriter(),
        );
    }

    private function config(): FtpConfig
    {
        return new FtpConfig(new GameServer('Test'), '127.0.0.1', 'user', 'secret');
    }
}

/** A file browser over one in-memory file, recording what it was told. */
final class RecordingBrowser implements FileBrowserInterface
{
    /** @var array<string, string> */
    public array $backups = [];

    public function __construct(
        public string $contents,
        /** What a read-back returns instead, to force a mismatch. */
        private readonly ?string $rewriteTo = null,
        private bool $written = false,
    ) {
    }

    public function readTail(FtpConfig $config, string $path, int $maxBytes = 65536): string
    {
        return $this->written && $this->rewriteTo !== null ? $this->rewriteTo : $this->contents;
    }

    public function upload(FtpConfig $config, string $path, string $contents): void
    {
        if (str_contains($path, '.zc-bak-')) {
            $this->backups[$path] = $contents;

            return;
        }

        $this->contents = $contents;
        $this->written = true;
    }

    public function delete(FtpConfig $config, string $path): void
    {
        unset($this->backups[$path]);
    }

    public function fileExists(FtpConfig $config, string $path): bool
    {
        return !str_contains($path, '.zc-bak-');
    }

    public function listDirectory(FtpConfig $config, string $path = ''): array
    {
        return ['path' => $path, 'entries' => []];
    }

    public function verify(FtpConfig $config): array
    {
        return ['path' => '/', 'entryCount' => 0, 'looksLikeZomboid' => true];
    }

    public function directoryExists(FtpConfig $config, string $path): bool
    {
        return true;
    }

    public function download(FtpConfig $config, string $path, string $target): int
    {
        throw new \LogicException('not used');
    }
}
