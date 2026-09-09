<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Mods;

use App\Entity\FtpConfig;
use App\Entity\GameServer;
use App\Server\Mods\ModInfoReader;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use PHPUnit\Framework\TestCase;

/**
 * The mod ids `Mods=` needs, which live only inside the downloaded item.
 *
 * Shapes taken from a real server: one item keeps mod.info at
 * `mods/<name>/mod.info`, another at `mods/<name>/<build>/mod.info`, and
 * a third has both.
 */
final class ModInfoReaderTest extends TestCase
{
    public function testReadsTheIdFromAModInfo(): void
    {
        $reader = new ModInfoReader($this->browser([
            'steamapps/workshop/content/108600' => [],
            'steamapps/workshop/content/108600/123' => [
                ['name' => 'mods', 'path' => 'x/mods', 'type' => 'directory'],
            ],
            'x/mods' => [
                ['name' => 'CommonSense', 'path' => 'x/mods/CommonSense', 'type' => 'directory'],
            ],
            'x/mods/CommonSense' => [
                ['name' => 'mod.info', 'path' => 'x/mods/CommonSense/mod.info', 'type' => 'file'],
            ],
        ], [
            'x/mods/CommonSense/mod.info' => "name=Common Sense\nid=BB_CommonSense\nauthor=BitBraven\n",
        ]));

        $verdict = $reader->read(self::config(), '123');

        self::assertSame('found', $verdict->state);
        self::assertSame(['BB_CommonSense'], $verdict->ids);
    }

    /**
     * The build-specific layout, which a fixed path would miss.
     */
    public function testFindsAModInfoOneLevelDeeperForABuildVariant(): void
    {
        $reader = new ModInfoReader($this->browser([
            'steamapps/workshop/content/108600' => [],
            'steamapps/workshop/content/108600/456' => [
                ['name' => 'mods', 'path' => 'y/mods', 'type' => 'directory'],
            ],
            'y/mods' => [['name' => 'PZ_Map', 'path' => 'y/mods/PZ_Map', 'type' => 'directory']],
            'y/mods/PZ_Map' => [['name' => '42', 'path' => 'y/mods/PZ_Map/42', 'type' => 'directory']],
            'y/mods/PZ_Map/42' => [
                ['name' => 'mod.info', 'path' => 'y/mods/PZ_Map/42/mod.info', 'type' => 'file'],
            ],
        ], [
            'y/mods/PZ_Map/42/mod.info' => "name=PZ Map\nid=PZ_Map\nversionMin=42.0.0\n",
        ]));

        $verdict = $reader->read(self::config(), '456');

        self::assertSame(['PZ_Map'], $verdict->ids);
        self::assertSame('42.0.0', $verdict->versionMin);
    }

    /**
     * One real item carries the same id twice, once per build folder.
     * Writing it into `Mods=` twice would load nothing extra and read
     * as a mistake.
     */
    public function testReportsTheSameIdOnlyOnceWhenTwoFilesCarryIt(): void
    {
        $reader = new ModInfoReader($this->browser([
            'steamapps/workshop/content/108600' => [],
            'steamapps/workshop/content/108600/789' => [
                ['name' => 'mods', 'path' => 'z/mods', 'type' => 'directory'],
            ],
            'z/mods' => [['name' => 'Sense', 'path' => 'z/mods/Sense', 'type' => 'directory']],
            'z/mods/Sense' => [
                ['name' => 'mod.info', 'path' => 'z/mods/Sense/mod.info', 'type' => 'file'],
                ['name' => '42.0', 'path' => 'z/mods/Sense/42.0', 'type' => 'directory'],
            ],
            'z/mods/Sense/42.0' => [
                ['name' => 'mod.info', 'path' => 'z/mods/Sense/42.0/mod.info', 'type' => 'file'],
            ],
        ], [
            'z/mods/Sense/mod.info' => "id=BB_CommonSense\n",
            'z/mods/Sense/42.0/mod.info' => "id=BB_CommonSense\n",
        ]));

        $verdict = $reader->read(self::config(), '789');

        self::assertSame(['BB_CommonSense'], $verdict->ids);
        self::assertCount(2, $verdict->paths, 'both files are still named');
    }

    /**
     * A map mod loads like any other, but its map only appears when the
     * folder is named in `Map=` as well — so it downloads, loads, and
     * stays invisible. Nothing in mod.info names it; the directory is
     * the only source.
     */
    public function testReadsTheMapFoldersAModShips(): void
    {
        $reader = new ModInfoReader($this->browser([
            'steamapps/workshop/content/108600' => [],
            'steamapps/workshop/content/108600/321' => [
                ['name' => 'mods', 'path' => 'm/mods', 'type' => 'directory'],
            ],
            'm/mods' => [['name' => 'Bedford', 'path' => 'm/mods/Bedford', 'type' => 'directory']],
            'm/mods/Bedford' => [
                ['name' => 'mod.info', 'path' => 'm/mods/Bedford/mod.info', 'type' => 'file'],
            ],
            'm/mods/Bedford/media/maps' => [
                ['name' => 'Bedford Falls, KY', 'path' => 'm/mods/Bedford/media/maps/Bedford Falls, KY', 'type' => 'directory'],
            ],
        ], [
            'm/mods/Bedford/mod.info' => "name=Bedford Falls
id=BedfordFalls
",
        ]));

        $verdict = $reader->read(self::config(), '321');

        self::assertSame(['BedfordFalls'], $verdict->ids);
        self::assertSame(['Bedford Falls, KY'], $verdict->maps);
    }

    /** A mod that ships no map has none, which is not a fault. */
    public function testAModWithoutMapsReportsNone(): void
    {
        $reader = new ModInfoReader($this->browser([
            'steamapps/workshop/content/108600' => [],
            'steamapps/workshop/content/108600/654' => [
                ['name' => 'mods', 'path' => 'n/mods', 'type' => 'directory'],
            ],
            'n/mods' => [['name' => 'Plain', 'path' => 'n/mods/Plain', 'type' => 'directory']],
            'n/mods/Plain' => [
                ['name' => 'mod.info', 'path' => 'n/mods/Plain/mod.info', 'type' => 'file'],
            ],
        ], ['n/mods/Plain/mod.info' => "id=Plain
"]));

        self::assertSame([], $reader->read(self::config(), '654')->maps);
    }

    /**
     * The commonest case and a benign one: the server downloads an item
     * at its next start, not when it is configured.
     */
    public function testSaysNotDownloadedRatherThanEmptyWhenTheItemIsAbsent(): void
    {
        $reader = new ModInfoReader($this->browser([
            'steamapps/workshop/content/108600' => [],
        ], []));

        self::assertSame('notDownloaded', $reader->read(self::config(), '123')->state);
    }

    public function testSaysSoWhenSteamsOwnDirectoryIsMissing(): void
    {
        $reader = new ModInfoReader($this->browser([], []));

        self::assertSame('noWorkshopDirectory', $reader->read(self::config(), '123')->state);
    }

    /** Without credentials there is nothing to read, which is not "none". */
    public function testSaysSoWithoutTransferCredentials(): void
    {
        $reader = new ModInfoReader($this->browser([], []));

        self::assertSame('noTransfer', $reader->read(null, '123')->state);
    }

    /**
     * A failed transfer must not read as "this item contains no mods":
    * one is a fault to fix, the other a fact about the mod.
     */
    public function testDistinguishesAFailedTransferFromAnEmptyResult(): void
    {
        $reader = new ModInfoReader(new class implements FileBrowserInterface {
            public function listDirectory(FtpConfig $config, string $path = ''): array
            {
                throw new StorageException('storage.unreachable', 'the transfer failed');
            }

            public function verify(FtpConfig $config): array { return []; }
            public function directoryExists(FtpConfig $config, string $path): bool { return true; }
            public function fileExists(FtpConfig $config, string $path): bool { return true; }
            public function readTail(FtpConfig $config, string $path, int $maxBytes = 65536): string { return ''; }
            public function upload(FtpConfig $config, string $path, string $contents): void {}
            public function delete(FtpConfig $config, string $path): void {}
            public function download(FtpConfig $config, string $path, string $target): int { return 0; }
        });

        self::assertSame('unreachable', $reader->read(self::config(), '123')->state);
    }

    private static function config(): FtpConfig
    {
        return new FtpConfig(new GameServer('Test'), 'example.invalid', 'user');
    }

    /**
     * @param array<string, list<array<string, mixed>>> $listings
     * @param array<string, string>                     $files
     */
    private function browser(array $listings, array $files): FileBrowserInterface
    {
        return new class($listings, $files) implements FileBrowserInterface {
            /**
             * @param array<string, list<array<string, mixed>>> $listings
             * @param array<string, string>                     $files
             */
            public function __construct(
                private readonly array $listings,
                private readonly array $files,
            ) {
            }

            public function listDirectory(FtpConfig $config, string $path = ''): array
            {
                return ['path' => $path, 'entries' => $this->listings[$path] ?? []];
            }

            public function verify(FtpConfig $config): array
            {
                return [];
            }

            public function directoryExists(FtpConfig $config, string $path): bool
            {
                return \array_key_exists($path, $this->listings);
            }

            public function fileExists(FtpConfig $config, string $path): bool
            {
                return \array_key_exists($path, $this->files);
            }

            public function readTail(FtpConfig $config, string $path, int $maxBytes = 65536): string
            {
                return $this->files[$path] ?? '';
            }

            public function upload(FtpConfig $config, string $path, string $contents): void {}
            public function delete(FtpConfig $config, string $path): void {}
            public function download(FtpConfig $config, string $path, string $target): int { return 0; }
        };
    }
}
