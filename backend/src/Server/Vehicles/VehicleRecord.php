<?php

declare(strict_types=1);

namespace App\Server\Vehicles;

/**
 * One vehicle as the save file holds it.
 *
 * Read from the server's own vehicles.db rather than from the bridge:
 * that database carries every vehicle the world has generated, while
 * the bridge only sees the ones in chunks a player keeps loaded.
 *
 * Paint colour is deliberately absent. It sits behind the part list,
 * whose entries carry nested inventory items of variable length, so it
 * cannot be reached by an external parser without reimplementing the
 * game's item serialisation. The bridge reads it instead, through
 * getColorHue() and its siblings.
 */
final readonly class VehicleRecord
{
    public function __construct(
        public int $id,
        public string $script,
        public float $x,
        public float $y,
        public int $z,
        /** Degrees clockwise from north; null when the rotation was unreadable. */
        public ?float $heading,
        /** Which texture variant the script declares for this vehicle. */
        public int $skin,
        public bool $engineRunning,
        public VehicleCondition $condition,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'script' => $this->script,
            'x' => (int) round($this->x),
            'y' => (int) round($this->y),
            'z' => $this->z,
            'heading' => $this->heading === null ? null : round($this->heading, 1),
            'skin' => $this->skin,
            'engineRunning' => $this->engineRunning,
            'condition' => $this->condition->toArray(),
        ];
    }
}
