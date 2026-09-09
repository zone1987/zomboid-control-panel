<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * A mod as a Discord embed: its cover, its facts and a link.
 *
 * A line of text names a mod; an embed shows it. The title links
 * straight to the workshop page, which is where somebody goes next
 * anyway — to read the description, the comments, or to subscribe.
 *
 * **The image is Steam's own URL, not the panel's.** Discord fetches it
 * from its own servers, so a cover served from the panel would have to
 * be reachable from the internet — and on a local or private
 * deployment it is not (rule 10j). Steam's CDN always is.
 */
final readonly class ModEmbed
{
    /** Discord's own limits; a longer field is refused outright. */
    private const MAX_TITLE = 256;
    private const MAX_DESCRIPTION = 4096;

    /** The panel's accent, so an embed reads as coming from here. */
    private const COLOUR = 0x34D399;

    /**
     * @return array<string, mixed>|null null when there is nothing worth showing
     */
    public static function of(?WorkshopItem $item): ?array
    {
        if ($item === null) {
            return null;
        }

        $fields = [];

        $size = self::size($item->fileSize);

        if ($size !== null) {
            $fields[] = ['name' => 'Größe', 'value' => $size, 'inline' => true];
        }

        if ($item->updatedAt !== null) {
            // Discord renders this as the reader's own local time,
            // which is the right answer for a channel spanning
            // timezones.
            $fields[] = [
                'name' => 'Aktualisiert',
                'value' => sprintf('<t:%d:d>', $item->updatedAt->getTimestamp()),
                'inline' => true,
            ];
        }

        $builds = $item->declaredBuilds();

        if ($builds !== []) {
            $fields[] = [
                'name' => \count($builds) === 1 ? 'Build' : 'Builds',
                'value' => implode(', ', $builds),
                'inline' => true,
            ];
        }

        $embed = [
            'title' => self::clip($item->title === '' ? $item->workshopId : $item->title, self::MAX_TITLE),
            'url' => $item->url(),
            'color' => self::COLOUR,
        ];

        // The tags say what kind of mod it is at a glance, which the
        // title often does not. The build tags are left out: they are
        // already a field of their own.
        $tags = array_values(array_filter(
            $item->tags,
            static fn (string $tag): bool => preg_match('/^Build \d+$/', $tag) !== 1,
        ));

        if ($tags !== []) {
            $embed['description'] = self::clip(implode(' · ', $tags), self::MAX_DESCRIPTION);
        }

        if ($item->previewUrl !== null) {
            $embed['thumbnail'] = ['url' => $item->previewUrl];
        }

        if ($fields !== []) {
            $embed['fields'] = $fields;
        }

        return $embed;
    }

    private static function size(int $bytes): ?string
    {
        if ($bytes <= 0) {
            return null;
        }

        $megabytes = $bytes / 1048576;

        return $megabytes >= 1
            ? number_format($megabytes, 1, ',', '.').' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }

    private static function clip(string $value, int $limit): string
    {
        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit - 1).'…';
    }
}
