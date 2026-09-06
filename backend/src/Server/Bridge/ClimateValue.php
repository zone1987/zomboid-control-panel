<?php

declare(strict_types=1);

namespace App\Server\Bridge;

/**
 * One of the thirteen climate floats, with what it is and who set it.
 *
 * Two states matter and read differently: the game is running this
 * value, or somebody pinned it. A pinned value stays where it was put
 * through the season change, which is what an operator needs to see
 * before wondering why July is freezing.
 */
final readonly class ClimateValue
{
    private function __construct(
        public string $name,
        public int $index,
        /** What the game is actually using. */
        public float $value,
        /** Whether an admin override is holding it there. */
        public bool $pinned,
        /** What the override is set to, meaningful only when pinned. */
        public float $pinnedValue,
        public float $min,
        public float $max,
    ) {
    }

    /** @param array<string, mixed> $value */
    public static function fromBridge(string $name, array $value): self
    {
        return new self(
            name: $name,
            index: \is_int($value['index'] ?? null) ? $value['index'] : -1,
            value: self::float($value, 'value'),
            pinned: ($value['admin'] ?? false) === true,
            pinnedValue: self::float($value, 'adminValue'),
            min: self::float($value, 'min'),
            // A float declares max 1 unless setup() overrode it, and a
            // zero ceiling would make every slider a single point.
            max: self::float($value, 'max') ?: 1.0,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'index' => $this->index,
            'value' => $this->value,
            'pinned' => $this->pinned,
            'pinnedValue' => $this->pinnedValue,
            'min' => $this->min,
            'max' => $this->max,
        ];
    }

    /** @param array<string, mixed> $value */
    private static function float(array $value, string $name): float
    {
        return is_numeric($value[$name] ?? null) ? (float) $value[$name] : 0.0;
    }
}
