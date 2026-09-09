<?php

declare(strict_types=1);

namespace App\Server\Mods;

use App\Entity\FtpConfig;
use App\Server\Config\ConfigKind;
use App\Server\Config\ConfigWriteRefused;
use App\Server\Config\ConfigWriter;

/**
 * Writes a mod list back to the server's INI.
 *
 * Deliberately built on ConfigWriter rather than beside it: the backup,
 * the read-back and the restore-on-mismatch are the whole reason this
 * is safe, and a second write path would have to repeat all three.
 *
 * The one thing it adds is naming the case ConfigWriter refuses. A file
 * without a `Mods=` line cannot be edited into having one, and an
 * operator told only "unknown key" has no idea what to do about it.
 */
final readonly class ModListWriter
{
    public function __construct(private ConfigWriter $writer)
    {
    }

    /**
     * @return array{
     *     status: string,
     *     missingKeys: list<string>,
     *     written: list<string>,
     *     backup: array{state: string, path: string|null, error: string|null}|null,
     *     verified: bool,
     *     mismatched: list<string>,
     *     restored: bool
     * }
     */
    public function write(FtpConfig $config, string $path, ModList $list): array
    {
        try {
            $outcome = $this->writer->apply($config, $path, ConfigKind::Ini, $list->toChanges());
        } catch (ConfigWriteRefused $refused) {
            // `config.unknownKeys` here means the file has no such line
            // at all. That is a real state with a real fix -- add the
            // line once -- and not something to retry or hide.
            return [
                'status' => $refused->getMessage() === 'config.unknownKeys' ? 'keysMissing' : 'refused',
                'missingKeys' => $refused->keys(),
                'written' => [],
                'backup' => null,
                'verified' => false,
                'mismatched' => [],
                'restored' => false,
            ];
        }

        return [
            'status' => $outcome['verified'] ? 'written' : 'notVerified',
            'missingKeys' => [],
            'written' => $outcome['written'],
            'backup' => $outcome['backup'],
            'verified' => $outcome['verified'],
            'mismatched' => $outcome['mismatched'],
            'restored' => $outcome['restored'],
        ];
    }
}
