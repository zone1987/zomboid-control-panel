<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * What the panel needs from the Steam Workshop.
 *
 * An interface so a functional test can answer without the network:
 * asserting an endpoint's shape must not depend on Valve being up, and
 * a test that really searched would be slow and flaky both.
 */
interface WorkshopSource
{
    public function hasKey(): bool;

    /**
     * @param list<string> $workshopIds
     *
     * @return WorkshopResult<WorkshopItem>
     */
    public function itemsById(array $workshopIds): WorkshopResult;

    /**
     * @param list<string> $tags
     *
     * @return WorkshopResult<WorkshopItem>
     */
    public function search(
        string $term = '',
        array $tags = [],
        string $sort = 'trend',
        int $page = 1,
        int $perPage = 30,
    ): WorkshopResult;

    /**
     * @return WorkshopResult<WorkshopItem>
     */
    public function details(string $workshopId): WorkshopResult;
}
