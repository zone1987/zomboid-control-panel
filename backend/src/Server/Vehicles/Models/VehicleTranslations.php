<?php

declare(strict_types=1);

namespace App\Server\Vehicles\Models;

use App\Entity\FtpConfig;
use App\Entity\GameServer;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use App\Server\Translation\TranslationVerdict;
use Psr\Cache\CacheItemPoolInterface;

/**
 * What the game calls each vehicle, in the reader's own language.
 *
 * {@see VehicleNames} holds the English names compiled in, which is
 * what an installation without the language file falls back to. The
 * game ships the rest under IGUI_VehicleName* in IG_UI.json, one file
 * per language -- the same source, read at run time so a Polish
 * operator sees "Karetka" rather than "Ambulance".
 */
final readonly class VehicleTranslations
{
    private const DIRECTORY = 'media/lua/shared/Translate';
    private const FILENAME = 'IG_UI.json';
    private const PREFIX = 'IGUI_VehicleName';

    /** Changes only when the game is updated. */
    private const TTL_SECONDS = 604800;

    /** A failure is worth retrying long before the game is patched. */
    private const FAILURE_TTL_SECONDS = 900;

    /** 628 kB in Russian, the largest shipped; the ceiling is generous. */
    private const MAX_BYTES = 4194304;

    public function __construct(
        private FileBrowserInterface $files,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array<string, string> bare script name to translated name,
     *                               empty when that language is absent
     */
    public function forLanguage(GameServer $server, string $language): array
    {
        return $this->verdictFor($server, $language)->names;
    }

    /** Why the names are, or are not, in that language. */
    public function verdictFor(GameServer $server, string $language, bool $refresh = false): TranslationVerdict
    {
        $code = self::normalise($language);

        if ($code === null) {
            return TranslationVerdict::unsupportedLanguage($language);
        }

        $entry = $this->cache->getItem(sprintf(
            'vehicles.names.%s.%s',
            $server->getId()->toRfc4122(),
            $code,
        ));

        $cached = $entry->get();

        if (!$refresh && $entry->isHit() && $cached instanceof TranslationVerdict) {
            return $cached;
        }

        $verdict = $this->read($server, $code);

        $entry->set($verdict)->expiresAfter(
            $verdict->needsAttention() ? self::FAILURE_TTL_SECONDS : self::TTL_SECONDS,
        );
        $this->cache->save($entry);

        return $verdict;
    }

    /**
     * The translated name, or the English one compiled in.
     *
     * @param array<string, string> $names from {@see self::forLanguage()}
     */
    public static function pick(array $names, string $script): string
    {
        $bare = str_contains($script, '.')
            ? substr($script, strrpos($script, '.') + 1)
            : $script;

        return $names[$bare] ?? VehicleNames::of($script);
    }

    /**
     * The catalogue with every name in the reader's language.
     *
     * A body tile is labelled after its plainest member, which the
     * catalogue already worked out in English -- so the translation is
     * carried across from the item rather than decided a second time.
     *
     * @param array<string, mixed>  $catalogue
     * @param array<string, string> $names from {@see self::forLanguage()}
     *
     * @return array<string, mixed>
     */
    public static function rename(array $catalogue, array $names): array
    {
        if ($names === [] || !\is_array($catalogue['items'] ?? null)) {
            return $catalogue;
        }

        $carried = [];

        foreach ($catalogue['items'] as $index => $vehicle) {
            if (!\is_array($vehicle) || !\is_string($vehicle['script'] ?? null)) {
                continue;
            }

            $name = self::pick($names, $vehicle['script']);
            $catalogue['items'][$index]['name'] = $name;

            if (\is_string($vehicle['body'] ?? null) && \is_string($vehicle['name'] ?? null)) {
                $carried[$vehicle['body']][$vehicle['name']] = $name;
            }
        }

        if (!\is_array($catalogue['bodies'] ?? null)) {
            return $catalogue;
        }

        foreach ($catalogue['bodies'] as $index => $body) {
            if (!\is_array($body) || !\is_string($body['id'] ?? null) || !\is_string($body['name'] ?? null)) {
                continue;
            }

            // "Name — plainest member": only the leading name is the
            // vehicle's, and the qualifier is a mask file name.
            $parts = explode(' — ', $body['name'], 2);
            $translated = $carried[$body['id']][$parts[0]] ?? null;

            if ($translated !== null) {
                $catalogue['bodies'][$index]['name'] = \count($parts) === 2
                    ? $translated.' — '.$parts[1]
                    : $translated;
            }
        }

        return $catalogue;
    }

    public static function normalise(string $language): ?string
    {
        $code = strtoupper(str_replace('-', '_', trim($language)));

        return preg_match('/^[A-Z]{2}(_[A-Z]{2})?$/', $code) === 1 ? $code : null;
    }

    /** @return array<string, string> */
    private function read(GameServer $server, string $code): TranslationVerdict
    {
        $config = $server->getFtpConfig();
        $path = sprintf('%s/%s/%s', self::DIRECTORY, $code, self::FILENAME);

        if ($config === null) {
            return TranslationVerdict::noCredentials();
        }

        try {
            $raw = $this->files->readTail($config, $path, self::MAX_BYTES);
        } catch (StorageException $exception) {
            return $this->explain($config, $code, $path, $exception);
        }

        $payload = json_decode(trim($raw), true);

        if (!\is_array($payload)) {
            return TranslationVerdict::unreadable($code, $path);
        }

        // Older builds nested everything under an IG_UI key; current
        // ones are flat. Accept both rather than guess which is here.
        if (isset($payload['IG_UI']) && \is_array($payload['IG_UI'])) {
            $payload = $payload['IG_UI'];
        }

        $names = [];

        foreach ($payload as $key => $name) {
            if (!\is_string($key) || !\is_string($name) || $name === '') {
                continue;
            }

            if (!str_starts_with($key, self::PREFIX)) {
                continue;
            }

            $script = substr($key, \strlen(self::PREFIX));

            // "Verbrannt %1" is the game filling in another name; the
            // panel has nothing to fill it with, so the English stands.
            if ($script === '' || str_contains($name, '%')) {
                continue;
            }

            $names[$script] = $name;
        }

        return $names === []
            ? TranslationVerdict::unreadable($code, $path)
            : TranslationVerdict::translated($code, $names, $path);
    }

    /** Whether the installation is reachable at all is a separate question. */
    private function explain(
        FtpConfig $config,
        string $code,
        string $path,
        StorageException $exception,
    ): TranslationVerdict {
        $fallback = TranslationVerdict::fromStorageFailure($code, $path, $exception->messageKey());

        if ($fallback->state === TranslationVerdict::UNREACHABLE) {
            return $fallback;
        }

        try {
            return $this->files->directoryExists($config, self::DIRECTORY)
                ? TranslationVerdict::noSuchLanguage($code, $path)
                : TranslationVerdict::pathMissing($code, $path);
        } catch (StorageException) {
            return $fallback;
        }
    }
}
