<?php

declare(strict_types=1);

namespace App\Server\Items;

use App\Entity\GameServer;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
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
        $code = self::normalise($language);

        if ($code === null) {
            return [];
        }

        $entry = $this->cache->getItem(sprintf(
            'items.names.%s.%s',
            $server->getId()->toRfc4122(),
            $code,
        ));

        if ($entry->isHit() && \is_array($entry->get())) {
            return $entry->get();
        }

        $names = $this->read($server, $code);

        $entry->set($names)->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($entry);

        return $names;
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

    /** @return array<string, string> */
    private function read(GameServer $server, string $code): array
    {
        $config = $server->getFtpConfig();

        if ($config === null) {
            return [];
        }

        try {
            $raw = $this->files->readTail(
                $config,
                sprintf('%s/%s/%s', self::DIRECTORY, $code, self::FILENAME),
                self::MAX_BYTES,
            );
        } catch (StorageException) {
            return [];
        }

        $payload = json_decode(trim($raw), true);

        if (!\is_array($payload)) {
            return [];
        }

        $names = [];

        foreach ($payload as $type => $name) {
            if (\is_string($type) && \is_string($name) && $name !== '') {
                $names[$type] = $name;
            }
        }

        return $names;
    }
}
