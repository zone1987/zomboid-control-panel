<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * What a set of mods needs, followed through the chain.
 *
 * Steam names an item's direct requirements; those requirements have
 * their own, so the answer to "what does this need" is a walk rather
 * than a lookup.
 *
 * Two bounds, both because the input comes from strangers: a **depth
 * limit**, since a chain of a hundred would cost a hundred round trips
 * for an answer nobody reads, and **cycle protection**, since A
 * requiring B requiring A is a thing an author can publish and would
 * otherwise spin forever.
 */
final readonly class DependencyGraph
{
    /** Deep enough for every real chain; shallow enough to stay quick. */
    private const MAX_DEPTH = 4;

    public function __construct(private WorkshopSource $workshop)
    {
    }

    /**
     * Resolves the full requirement set of these workshop ids.
     *
     * @param list<string> $workshopIds
     */
    public function resolve(array $workshopIds): DependencyResolution
    {
        $seen = [];
        $required = [];
        $edges = [];
        $truncated = false;

        $frontier = array_values(array_unique($workshopIds));

        foreach ($frontier as $id) {
            $seen[$id] = true;
        }

        for ($depth = 0; $depth < self::MAX_DEPTH && $frontier !== []; ++$depth) {
            $answer = $this->workshop->itemsById($frontier);

            if (!$answer->succeeded()) {
                return DependencyResolution::failed($answer->state);
            }

            $next = [];

            foreach ($answer->items as $item) {
                // A collection's children are its contents rather than
                // its requirements; walking them would pull in a whole
                // curated list as though the mod needed all of it.
                if ($item->isCollection) {
                    continue;
                }

                foreach ($item->dependencies as $childId) {
                    $edges[] = [$item->workshopId, $childId];

                    if (isset($seen[$childId])) {
                        continue;
                    }

                    $seen[$childId] = true;
                    $required[] = $childId;
                    $next[] = $childId;
                }
            }

            $frontier = $next;
        }

        // Still something to follow when the budget ran out: the answer
        // is incomplete, and saying so beats implying it is whole.
        if ($frontier !== []) {
            $truncated = true;
        }

        return DependencyResolution::resolved($required, $edges, $truncated);
    }

    /**
     * The ids these mods need that are not among them.
     *
     * @param list<string> $installed
     * @param list<string> $required
     *
     * @return list<string>
     */
    public static function missing(array $installed, array $required): array
    {
        return array_values(array_filter(
            $required,
            static fn (string $id): bool => !\in_array($id, $installed, true),
        ));
    }
}
