<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * Puts a mod list in an order the game can load.
 *
 * `Mods=` is read positionally, so what a mod needs has to appear
 * before it. A dependency graph plus a topological sort answers that —
 * but only where the graph is acyclic, and a published pair of mods
 * requiring each other is not.
 *
 * **A cycle is a named outcome, not an exception.** Refusing to sort
 * and saying which mods are tangled is useful; throwing is not, and
 * silently emitting some order would be worse than both.
 */
final readonly class LoadOrder
{
    /**
     * @param list<string>                      $ids   in the order the file has them
     * @param list<array{0: string, 1: string}> $edges parent requires child
     */
    public static function sort(array $ids, array $edges): LoadOrderVerdict
    {
        $known = array_values(array_unique($ids));
        $position = array_flip($known);

        // Only edges between mods actually in the list: a requirement
        // nobody installed cannot be ordered, and is reported as
        // missing elsewhere rather than silently added here.
        $dependencies = [];
        $dependents = [];

        foreach ($known as $id) {
            $dependencies[$id] = [];
            $dependents[$id] = [];
        }

        foreach ($edges as [$parent, $child]) {
            if (!isset($position[$parent], $position[$child]) || $parent === $child) {
                continue;
            }

            if (\in_array($child, $dependencies[$parent], true)) {
                continue;
            }

            $dependencies[$parent][] = $child;
            $dependents[$child][] = $parent;
        }

        $remaining = $dependencies;
        $sorted = [];

        while ($remaining !== []) {
            // Ready mods keep their existing relative order, so a list
            // that needs no change comes back unchanged rather than
            // shuffled into an equally valid but unfamiliar order.
            $ready = [];

            foreach ($remaining as $id => $needs) {
                if ($needs === []) {
                    $ready[] = $id;
                }
            }

            if ($ready === []) {
                // Everything left is part of, or behind, a cycle.
                return LoadOrderVerdict::cycle(array_keys($remaining));
            }

            usort($ready, static fn (string $a, string $b): int => $position[$a] <=> $position[$b]);

            foreach ($ready as $id) {
                $sorted[] = $id;
                unset($remaining[$id]);

                foreach ($dependents[$id] as $parent) {
                    if (!isset($remaining[$parent])) {
                        continue;
                    }

                    $remaining[$parent] = array_values(array_filter(
                        $remaining[$parent],
                        static fn (string $held): bool => $held !== $id,
                    ));
                }
            }
        }

        return LoadOrderVerdict::sorted($sorted, $sorted !== $known);
    }
}
