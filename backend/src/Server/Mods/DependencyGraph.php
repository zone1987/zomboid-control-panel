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
            $items = [];

            // One call per mod, because only the authenticated endpoint
            // carries `children` -- the batch one omits the field
            // entirely, so asking it would always find no dependencies
            // at all. Measured against a mod that really has one.
            foreach ($frontier as $id) {
                $answer = $this->workshop->details($id);

                if (!$answer->succeeded()) {
                    return DependencyResolution::failed($answer->state);
                }

                $found = $answer->first();

                if ($found !== null) {
                    $items[] = $found;
                }
            }

            $next = [];

            foreach ($items as $item) {
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
     * The requirement chain of one mod, as a tree the interface can draw.
     *
     * Depth and cycles are bounded the same way as `resolve`, and a mod
     * already seen higher up is marked rather than expanded again — a
     * circle would otherwise draw forever, and a diamond would repeat
     * the same subtree twice for no gain.
     *
     * @return array<string, mixed>
     */
    public function tree(string $workshopId): array
    {
        $resolution = $this->resolve([$workshopId]);

        if (!$resolution->succeeded()) {
            return ['state' => $resolution->state->value, 'nodes' => [], 'truncated' => false];
        }

        $ids = [$workshopId, ...$resolution->required];
        $described = $this->workshop->itemsById($ids);
        $byId = [];

        foreach ($described->items as $item) {
            $byId[$item->workshopId] = $item;
        }

        $children = [];

        foreach ($resolution->edges as [$parent, $child]) {
            $children[$parent][] = $child;
        }

        return [
            'state' => WorkshopState::Ok->value,
            'nodes' => [$this->node($workshopId, $children, $byId, [], 0)],
            'truncated' => $resolution->truncated,
        ];
    }

    /**
     * @param array<string, list<string>>       $children
     * @param array<string, WorkshopItem>       $byId
     * @param list<string>                      $ancestors
     *
     * @return array<string, mixed>
     */
    private function node(string $id, array $children, array $byId, array $ancestors, int $depth): array
    {
        $item = $byId[$id] ?? null;

        // Already above us in this branch: expanding it again is what a
        // circle does, so it is named and left closed instead.
        $repeats = \in_array($id, $ancestors, true);

        $node = [
            'workshopId' => $id,
            'title' => $item?->title,
            'resolved' => $item !== null,
            'repeats' => $repeats,
            'children' => [],
        ];

        if ($repeats || $depth >= self::MAX_DEPTH) {
            return $node;
        }

        foreach (array_unique($children[$id] ?? []) as $childId) {
            $node['children'][] = $this->node(
                $childId,
                $children,
                $byId,
                [...$ancestors, $id],
                $depth + 1,
            );
        }

        return $node;
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
