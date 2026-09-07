<?php

declare(strict_types=1);

namespace App\Server\Config;

use App\Entity\FtpConfig;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;

/**
 * Finds a server's settings files rather than guessing their names.
 *
 * `servertest.ini` and `servertest_SandboxVars.lua` are named after the
 * *server*, not after the game: on a real host they are called
 * something else entirely. So the directory is listed and matched by
 * pattern, and what is found is reported with its actual name.
 *
 * Three outcomes, and the middle one is why this is not a boolean:
 * exactly one match, **several** matches (the operator chooses — a host
 * that keeps two configurations side by side is normal, not an error),
 * or none, in which case the answer says where it looked.
 */
final readonly class ConfigFileLocator
{
    /**
     * Only the directory that actually holds them, rather than a walk:
     * listings are capped at 500 entries and an installation root has
     * hundreds. Verified against a live server.
     *
     * @var list<string>
     */
    private const CANDIDATE_DIRECTORIES = ['Server', 'server'];

    public function __construct(private FileBrowserInterface $files)
    {
    }

    /**
     * @return array{
     *     directory: string|null,
     *     searched: list<string>,
     *     ini: list<string>,
     *     sandbox: list<string>,
     *     spawnRegions: list<string>,
     *     spawnPoints: list<string>,
     *     error: string|null
     * }
     */
    public function locate(FtpConfig $config): array
    {
        $searched = $this->directoriesToSearch($config);

        foreach ($searched as $directory) {
            try {
                if (!$this->files->directoryExists($config, $directory)) {
                    continue;
                }

                $listing = $this->files->listDirectory($config, $directory);
            } catch (StorageException $exception) {
                return $this->nothing($searched, $exception->messageKey());
            }

            $names = array_map(
                static fn (array $entry): string => $entry['name'],
                array_values(array_filter(
                    $listing['entries'],
                    static fn (array $entry): bool => $entry['type'] === 'file',
                )),
            );

            $found = [
                // _SandboxVars.lua and _spawnregions.lua are also `.lua`
                // and also `.ini`-adjacent, so each pattern excludes the
                // others rather than relying on the order they matched in.
                // Not every .ini is a server configuration: options.ini
                // sits in the installation root. Verified on a live
                // server, whose Server/ directory holds exactly
                // servertest.ini plus the three _*.lua files.
                'ini' => $this->matching($names, '/^(?!options\.).+\.ini$/i'),
                'sandbox' => $this->matching($names, '/_SandboxVars\.lua$/i'),
                'spawnRegions' => $this->matching($names, '/_spawnregions\.lua$/i'),
                'spawnPoints' => $this->matching($names, '/_spawnpoints\.lua$/i'),
            ];

            if ($found['ini'] === [] && $found['sandbox'] === []) {
                continue;
            }

            return [
                'directory' => $directory,
                'searched' => $searched,
                ...$found,
                'error' => null,
            ];
        }

        return $this->nothing($searched, null);
    }

    /**
     * Where to look, in order.
     *
     * `Server/` beside the saves, which is where a live G-Portal host
     * keeps them. The lower-case variant is tried too because the game
     * runs on Linux, where the two are different directories.
     *
     * @return list<string>
     */
    private function directoriesToSearch(FtpConfig $config): array
    {
        return self::CANDIDATE_DIRECTORIES;
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function matching(array $names, string $pattern): array
    {
        $matched = array_values(array_filter(
            $names,
            static fn (string $name): bool => preg_match($pattern, $name) === 1,
        ));

        sort($matched);

        return $matched;
    }

    /**
     * @param list<string> $searched
     *
     * @return array<string, mixed>
     */
    private function nothing(array $searched, ?string $error): array
    {
        return [
            'directory' => null,
            'searched' => $searched,
            'ini' => [],
            'sandbox' => [],
            'spawnRegions' => [],
            'spawnPoints' => [],
            'error' => $error,
        ];
    }
}
