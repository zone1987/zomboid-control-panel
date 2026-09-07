<?php

declare(strict_types=1);

namespace App\Server\Items\Icons;

use Psr\Log\LoggerInterface;

/**
 * Cuts the individual icons out of a texture pack and stores them.
 *
 * The pictures belong to The Indie Stone. They are extracted from the
 * operator's own installation into their own storage, and never shipped
 * with this panel.
 */
final readonly class IconExtractor
{
    /** What the items page needs, and the default. */
    public const ITEM_ICONS = ['Item_'];

    /** The character sheet's own artwork: professions and traits. */
    public const CHARACTER_ICONS = ['profession_', 'trait_'];


    public function __construct(
        private IconStore $store,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $prefixes sprite name prefixes worth keeping
     * @param IconStore|null $into    where to write, defaulting to the item store
     *
     * @return array{extracted: int, skipped: int, pages: int}
     *
     * @throws MalformedPack
     */
    public function extract(
        string $packBytes,
        string $packName,
        array $prefixes = self::ITEM_ICONS,
        ?IconStore $into = null,
    ): array {
        $store = $into ?? $this->store;
        $pack = SpritePack::parse($packBytes);

        $extracted = 0;
        $skipped = 0;

        foreach ($pack->pages as $page) {
            $atlas = @imagecreatefromstring($page->png);

            if ($atlas === false) {
                $this->logger->info('A pack page held no readable image.', [
                    'pack' => $packName,
                    'page' => $page->name,
                ]);
                ++$skipped;

                continue;
            }

            $width = imagesx($atlas);
            $height = imagesy($atlas);

            foreach ($page->sprites as $sprite) {
                if (!$sprite->hasAnyPrefix($prefixes)) {
                    continue;
                }

                // A crop reaching past the atlas means the page parse
                // desynchronised. Dropping it is right: a clamped crop is
                // silently the wrong picture.
                if (
                    $sprite->width < 1 || $sprite->height < 1
                    || $sprite->x < 0 || $sprite->y < 0
                    || $sprite->x + $sprite->width > $width
                    || $sprite->y + $sprite->height > $height
                ) {
                    ++$skipped;

                    continue;
                }

                $png = $this->crop($atlas, $sprite);

                if ($png === null) {
                    ++$skipped;

                    continue;
                }

                $store->put($sprite->name, $png);
                ++$extracted;
            }

            imagedestroy($atlas);
        }

        return ['extracted' => $extracted, 'skipped' => $skipped, 'pages' => \count($pack->pages)];
    }

    private function crop(\GdImage $atlas, Sprite $sprite): ?string
    {
        $icon = imagecreatetruecolor($sprite->width, $sprite->height);

        if ($icon === false) {
            return null;
        }

        try {
            // Icons are cut out of a transparent sheet, so the copy has
            // to carry the alpha channel rather than flatten it.
            imagealphablending($icon, false);
            imagesavealpha($icon, true);
            imagefill($icon, 0, 0, imagecolorallocatealpha($icon, 0, 0, 0, 127));

            imagecopy($icon, $atlas, 0, 0, $sprite->x, $sprite->y, $sprite->width, $sprite->height);

            ob_start();
            imagepng($icon, null, 9);

            return (string) ob_get_clean();
        } finally {
            imagedestroy($icon);
        }
    }
}
