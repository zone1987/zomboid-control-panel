<?php

declare(strict_types=1);

namespace App\Server\Items;

use App\Entity\FtpConfig;
use App\Entity\GameServer;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use App\Server\Translation\TranslationVerdict;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Item names in the language the panel is being read in.
 *
 * The bridge can only report the one language the server runs in --
 * Translator has a single active locale per process, and switching it
 * would affect the running game. The translation files themselves are
 * on the server though, one per language, keyed by the same item type
 * the catalogue uses. Reading those directly gives every language
 * without touching the server at all.
 */
final readonly class ItemTranslations
{
    private const DIRECTORY = 'media/lua/shared/Translate';
    private const FILENAME = 'ItemName.json';

    /** Changes only when the game is updated. */
    private const TTL_SECONDS = 604800;

    /** A failure is worth retrying long before the game is patched. */
    private const FAILURE_TTL_SECONDS = 900;

    /** Roughly 260 kB per language; the ceiling is generous on purpose. */
    private const MAX_BYTES = 4194304;

    public function __construct(
        private FileBrowserInterface $files,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array<string, string> item type to translated name, empty
     *                               when that language is not installed
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
            'items.names.%s.%s',
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
     * The panel speaks "de" and "en"; the game names its folders "DE"
     * and "EN". Anything not shaped like a language code is refused
     * rather than turned into a path.
     */
    public static function normalise(string $language): ?string
    {
        $code = strtoupper(str_replace('-', '_', trim($language)));

        return preg_match('/^[A-Z]{2}(_[A-Z]{2})?$/', $code) === 1 ? $code : null;
    }

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

        $names = [];

        foreach ($payload as $type => $name) {
            if (\is_string($type) && \is_string($name) && $name !== '') {
                $names[$type] = $name;
            }
        }

        return $names === []
            ? TranslationVerdict::unreadable($code, $path)
            : TranslationVerdict::translated($code, $names, $path);
    }

    /**
     * A read that failed says only that; whether the installation is
     * reachable at all is a separate question, and its answer names a
     * different fix.
     */
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
