<?php

declare(strict_types=1);

namespace App\Server\Config;

use App\Entity\FtpConfig;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;

/**
 * Changes values in a server's settings file, and proves it afterwards.
 *
 * **Editing only — never deleting, never adding.** A key the request
 * names must already exist in the file, and a key the file holds that
 * the request does not name is left byte-for-byte alone. So a mod's
 * options survive, hand-written comments and spacing survive, and there
 * is no path through this class that removes a setting.
 *
 * The sequence, and every step of it exists because skipping it has
 * cost somebody a server:
 *
 *   1. read the file as it is
 *   2. back it up (three states — see ConfigBackup)
 *   3. replace only the values that changed, in place
 *   4. write
 *   5. **read back and compare every changed key**
 *   6. on a mismatch, restore the backup and say so
 */
final readonly class ConfigWriter
{
    /** `readTail` reads from the end, so this has to exceed the file. */
    private const MAX_BYTES = 1048576;

    public function __construct(
        private FileBrowserInterface $files,
        private ConfigBackup $backup,
        private LuaTableReader $lua,
        private LuaTableWriter $luaWriter,
        private IniWriter $iniWriter,
    ) {
    }

    /**
     * @param array<string, bool|float|int|string> $changes keyed as the file keys them
     *
     * @return array{
     *     written: list<string>,
     *     backup: array{state: string, path: string|null, error: string|null},
     *     verified: bool,
     *     mismatched: list<string>,
     *     restored: bool,
     *     error: string|null
     * }
     *
     * @throws StorageException     when the file cannot be read at all
     * @throws ConfigWriteRefused   when a change is not an edit of an existing value
     */
    public function apply(FtpConfig $config, string $path, ConfigKind $kind, array $changes): array
    {
        if ($changes === []) {
            throw new ConfigWriteRefused('config.nothingToWrite');
        }

        $before = $this->files->readTail($config, $path, self::MAX_BYTES);
        $held = $this->parse($before, $kind);

        // Editing only: a key that is not already there would be an
        // addition, and one the file has and the request omits is
        // untouched rather than removed.
        $absent = array_values(array_diff(array_keys($changes), array_keys($held)));

        if ($absent !== []) {
            throw new ConfigWriteRefused('config.unknownKeys', $absent);
        }

        $effective = [];

        foreach ($changes as $key => $value) {
            // Writing a value that is already there would still cost a
            // backup and a round trip for no change.
            if ($this->same($held[$key], $value)) {
                continue;
            }

            $effective[$key] = $value;
        }

        if ($effective === []) {
            return [
                'written' => [],
                'backup' => ['state' => 'nothing-to-back-up', 'path' => null, 'error' => null],
                'verified' => true,
                'mismatched' => [],
                'restored' => false,
                'error' => null,
            ];
        }

        $backup = $this->backup->create($config, $path, $before);
        $after = $this->replace($before, $kind, $effective);

        $this->files->upload($config, $path, $after);

        // Read back from the server rather than trusting the write, and
        // compare per key: a file that came back byte-identical to what
        // we sent still proves nothing if the server rewrote it.
        $reread = $this->parse($this->files->readTail($config, $path, self::MAX_BYTES), $kind);
        $mismatched = [];

        foreach ($effective as $key => $value) {
            if (!array_key_exists($key, $reread) || !$this->same($reread[$key], $value)) {
                $mismatched[] = $key;
            }
        }

        if ($mismatched !== []) {
            $restored = $this->restore($config, $path, $before);

            return [
                'written' => [],
                'backup' => $backup,
                'verified' => false,
                'mismatched' => $mismatched,
                'restored' => $restored,
                'error' => 'config.readBackMismatch',
            ];
        }

        $this->backup->prune($config, $path);

        return [
            'written' => array_keys($effective),
            'backup' => $backup,
            'verified' => true,
            'mismatched' => [],
            'restored' => false,
            'error' => null,
        ];
    }

    private function restore(FtpConfig $config, string $path, string $original): bool
    {
        try {
            $this->files->upload($config, $path, $original);
        } catch (StorageException) {
            return false;
        }

        return true;
    }

    /** @return array<string, bool|float|int|string> */
    private function parse(string $raw, ConfigKind $kind): array
    {
        return $kind === ConfigKind::Sandbox
            ? $this->lua->read($raw)
            : $this->iniWriter->read($raw);
    }

    /** @param array<string, bool|float|int|string> $changes */
    private function replace(string $raw, ConfigKind $kind, array $changes): string
    {
        return $kind === ConfigKind::Sandbox
            ? $this->luaWriter->apply($raw, $changes)
            : $this->iniWriter->apply($raw, $changes);
    }

    /**
     * Compares as the file would hold it.
     *
     * A float read back as `1` against a requested `1.0` is the same
     * value, and failing a write over that would restore a backup for
     * nothing.
     */
    private function same(bool|float|int|string $held, bool|float|int|string $wanted): bool
    {
        if (is_bool($held) || is_bool($wanted)) {
            return $held === $wanted;
        }

        if (is_numeric($held) && is_numeric($wanted)) {
            return abs((float) $held - (float) $wanted) < 0.000001;
        }

        return (string) $held === (string) $wanted;
    }
}
