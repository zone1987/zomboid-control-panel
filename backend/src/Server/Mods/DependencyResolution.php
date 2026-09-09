<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * What a dependency walk found, and whether it finished.
 *
 * `truncated` is its own field rather than a silent shortening: an
 * incomplete answer presented as complete would tell an operator their
 * mod list is satisfied when it is not.
 */
final readonly class DependencyResolution
{
    /**
     * @param list<string>              $required
     * @param list<array{0: string, 1: string}> $edges parent, child
     */
    private function __construct(
        public WorkshopState $state,
        public array $required = [],
        public array $edges = [],
        public bool $truncated = false,
    ) {
    }

    /**
     * @param list<string>                      $required
     * @param list<array{0: string, 1: string}> $edges
     */
    public static function resolved(array $required, array $edges, bool $truncated): self
    {
        return new self(WorkshopState::Ok, $required, $edges, $truncated);
    }

    public static function failed(WorkshopState $state): self
    {
        return new self($state);
    }

    public function succeeded(): bool
    {
        return $this->state === WorkshopState::Ok;
    }
}
