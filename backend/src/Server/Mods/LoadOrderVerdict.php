<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * An order the game can load, or the reason there is none.
 *
 * `changed` says whether it differs from what the file already holds,
 * so the interface can offer to apply it only when that would do
 * something — a button that rewrites the file to the same value is a
 * button that lies about having worked.
 */
final readonly class LoadOrderVerdict
{
    /**
     * @param list<string> $order   the sorted ids, empty on a cycle
     * @param list<string> $tangled the ids a cycle runs through
     */
    private function __construct(
        public string $state,
        public array $order = [],
        public bool $changed = false,
        public array $tangled = [],
    ) {
    }

    /**
     * @param list<string> $order
     */
    public static function sorted(array $order, bool $changed): self
    {
        return new self('sorted', $order, $changed);
    }

    /**
     * @param list<string> $tangled
     */
    public static function cycle(array $tangled): self
    {
        return new self('cycle', [], false, $tangled);
    }

    public function succeeded(): bool
    {
        return $this->state === 'sorted';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'order' => $this->order,
            'changed' => $this->changed,
            'tangled' => $this->tangled,
        ];
    }
}
