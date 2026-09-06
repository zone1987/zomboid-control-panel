<?php

declare(strict_types=1);

namespace App\Server\Vehicles;

/**
 * How battered a vehicle is, front and rear.
 *
 * The game keeps a maximum and a current value for each end. Equal
 * values mean undamaged; the panel shows where a vehicle was hit.
 */
final readonly class VehicleCondition
{
    public function __construct(
        public int $frontMax,
        public int $front,
        public int $rearMax,
        public int $rear,
    ) {
    }

    public function isDamaged(): bool
    {
        return $this->front < $this->frontMax || $this->rear < $this->rearMax;
    }

    /** Nothing left at either end: the vehicle is wrecked, not merely dented. */
    public function isWrecked(): bool
    {
        return $this->front <= 0 || $this->rear <= 0;
    }

    /** Share of the vehicle's total durability still intact, 0 to 1. */
    public function intact(): float
    {
        $max = $this->frontMax + $this->rearMax;

        if ($max <= 0) {
            return 1.0;
        }

        return max(0.0, min(1.0, ($this->front + $this->rear) / $max));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'front' => $this->front,
            'frontMax' => $this->frontMax,
            'rear' => $this->rear,
            'rearMax' => $this->rearMax,
            'damaged' => $this->isDamaged(),
            'wrecked' => $this->isWrecked(),
            'intact' => round($this->intact(), 3),
        ];
    }
}
