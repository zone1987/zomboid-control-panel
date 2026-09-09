<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * One mod as the interface needs it.
 *
 * Kept out of the controller so the shape is defined once: the listing,
 * the detail page and the installed list all render the same fields.
 */
final readonly class ModPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(
        string $workshopId,
        ?WorkshopItem $item,
        ?GameBuild $build = null,
        ?string $coverBase = null,
    ): array {
        if ($item === null) {
            // Installed but undescribed. Saying so beats an empty card:
            // the id may be private, removed from the workshop, or the
            // lookup may simply have failed.
            return [
                'workshopId' => $workshopId,
                'resolved' => false,
                'title' => null,
                'description' => null,
                'previewUrl' => null,
                'tags' => [],
                'fileSize' => null,
                'createdAt' => null,
                'updatedAt' => null,
                'subscriptions' => null,
                'favourites' => null,
                'views' => null,
                'dependencies' => [],
                'isCollection' => false,
                'isMap' => false,
                'declaredBuild' => null,
                'declaredBuilds' => [],
                // Unknown, not "fine": nothing is known about a mod
                // that could not be described (rule 6c).
                'buildVerdict' => 'unknown',
                'url' => 'https://steamcommunity.com/sharedfiles/filedetails/?id='.$workshopId,
            ];
        }

        return [
            'workshopId' => $item->workshopId,
            'resolved' => true,
            'title' => $item->title,
            'description' => $item->description,
            // The panel's own address, never Steam's: the CSP allows
            // only `self` for images, and pointing at Valve would leak
            // every viewer's address for a thumbnail.
            'previewUrl' => $item->previewUrl === null || $coverBase === null
                ? null
                : rtrim($coverBase, '/').'/'.$item->workshopId.'/cover',
            'tags' => $item->tags,
            'fileSize' => $item->fileSize,
            'createdAt' => $item->createdAt?->format(\DATE_ATOM),
            'updatedAt' => $item->updatedAt?->format(\DATE_ATOM),
            'subscriptions' => $item->subscriptions,
            'favourites' => $item->favourites,
            'views' => $item->views,
            'dependencies' => $item->dependencies,
            'isCollection' => $item->isCollection,
            'isMap' => $item->isMap(),
            'declaredBuild' => $item->declaredBuild(),
            'declaredBuilds' => $item->declaredBuilds(),
            'buildVerdict' => self::buildVerdict($item, $build),
            'url' => $item->url(),
        ];
    }

    /**
     * How this mod stands against the server's build.
     *
     * Three values rather than a boolean: "the mod declares nothing" is
     * not the same as "it matches", and neither is the same as nobody
     * knowing what the server runs.
     */
    private static function buildVerdict(WorkshopItem $item, ?GameBuild $build): string
    {
        if ($build === null || !$build->isKnown()) {
            return 'unknown';
        }

        if ($item->declaredBuilds() === []) {
            return 'undeclared';
        }

        return $build->conflictsWith($item) ? 'mismatch' : 'match';
    }
}
