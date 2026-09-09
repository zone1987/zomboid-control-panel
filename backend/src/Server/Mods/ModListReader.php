<?php

declare(strict_types=1);

namespace App\Server\Mods;

use App\Entity\FtpConfig;
use App\Server\Config\IniWriter;
use App\Server\Storage\FileBrowserInterface;

/**
 * Reads the three mod fields out of a server's INI.
 *
 * `IniWriter::read` rather than `ConfigReader::ini`: the latter joins
 * every value to its schema entry for the config screen, and this only
 * needs three raw strings.
 */
final readonly class ModListReader
{
    /** `readTail` reads from the end, so this has to exceed the file. */
    private const MAX_BYTES = 1048576;

    public function __construct(
        private FileBrowserInterface $files,
        private IniWriter $ini,
    ) {
    }

    /**
     * @throws \App\Server\Storage\StorageException when the file cannot be read
     */
    public function read(FtpConfig $config, string $path): ModList
    {
        $held = $this->ini->read($this->files->readTail($config, $path, self::MAX_BYTES));

        return new ModList(
            workshopIds: ModList::split(self::stringOf($held, ModList::WORKSHOP_KEY)),
            modIds: ModList::split(self::stringOf($held, ModList::MODS_KEY)),
            maps: ModList::split(self::stringOf($held, ModList::MAP_KEY)),
        );
    }

    /**
     * Which of the three keys the file does not have.
     *
     * A missing key cannot be written by ConfigWriter, so the interface
     * has to say which line to add rather than fail at save time.
     *
     * @return list<string>
     */
    public function missingKeys(FtpConfig $config, string $path): array
    {
        $held = $this->ini->read($this->files->readTail($config, $path, self::MAX_BYTES));

        return array_values(array_filter(
            [ModList::WORKSHOP_KEY, ModList::MODS_KEY, ModList::MAP_KEY],
            static fn (string $key): bool => !\array_key_exists($key, $held),
        ));
    }

    /**
     * @param array<string, bool|float|int|string> $held
     */
    private static function stringOf(array $held, string $key): ?string
    {
        $value = $held[$key] ?? null;

        return \is_string($value) ? $value : null;
    }
}
