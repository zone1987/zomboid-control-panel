<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * A lookup's verdict together with whatever it found.
 *
 * An empty list is a state, not an absence: "the search found nothing"
 * and "the search could not run" look identical once the items are
 * handed over on their own.
 *
 * @template T
 */
final readonly class WorkshopResult
{
    /**
     * @param list<T> $items
     */
    private function __construct(
        public WorkshopState $state,
        public array $items,
        public int $total,
    ) {
    }

    /**
     * @param list<T> $items
     *
     * @return self<T>
     */
    public static function ok(array $items, ?int $total = null): self
    {
        return new self(WorkshopState::Ok, $items, $total ?? \count($items));
    }

    /**
     * @return self<T>
     */
    public static function failed(WorkshopState $state): self
    {
        return new self($state, [], 0);
    }

    public function succeeded(): bool
    {
        return $this->state === WorkshopState::Ok;
    }

    /**
     * @return T|null
     */
    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    /**
     * How long this answer may be cached.
     *
     * A failure gets minutes and a fact a day: caching "no key" for a
     * week would keep the panel broken long after the key was entered.
     */
    public function cacheSeconds(): int
    {
        return $this->state->isFailure() ? 120 : 86400;
    }
}
