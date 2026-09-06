<?php

declare(strict_types=1);

namespace App\Server\Bridge;

/**
 * Whether the power and the water are still running.
 *
 * There is no on/off flag in the game: a utility runs until the world is
 * older than its shut-off day, counted in days since the outbreak. The
 * bridge reports both the state and that day, because "on" alone cannot
 * say *until when* — and an operator looking at a server three days from
 * a blackout wants to know.
 */
final readonly class UtilityReading
{
    /** The game's own "never shuts off". */
    public const NEVER = -1;

    private function __construct(
        public float $day,
        public bool $powerOn,
        public ?int $powerShutAt,
        public bool $waterOn,
        public ?int $waterShutAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data the bridge's own answer
     *
     * @throws BridgeCommandFailed when the answer is not a utility reading
     */
    public static function fromBridge(array $data): self
    {
        $power = $data['power'] ?? null;
        $water = $data['water'] ?? null;

        if (!\is_array($power) || !\is_array($water)) {
            throw new BridgeCommandFailed(
                'The bridge answered without the utilities.',
                'bridge.badAnswer',
            );
        }

        return new self(
            day: is_numeric($data['day'] ?? null) ? (float) $data['day'] : 0.0,
            powerOn: ($power['on'] ?? false) === true,
            powerShutAt: self::shutAt($power),
            waterOn: ($water['on'] ?? false) === true,
            waterShutAt: self::shutAt($water),
        );
    }

    /**
     * How many days are left before a utility stops, when that is known.
     *
     * Null where it never stops, or where it already has: neither is a
     * countdown, and showing "-4 days remaining" would be worse than
     * showing nothing.
     */
    public function daysLeft(bool $power): ?int
    {
        $shutAt = $power ? $this->powerShutAt : $this->waterShutAt;
        $on = $power ? $this->powerOn : $this->waterOn;

        if ($shutAt === null || $shutAt === self::NEVER || !$on) {
            return null;
        }

        return max(0, (int) ceil($shutAt - $this->day));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'day' => $this->day,
            'power' => [
                'on' => $this->powerOn,
                'shutAt' => $this->powerShutAt,
                'daysLeft' => $this->daysLeft(true),
            ],
            'water' => [
                'on' => $this->waterOn,
                'shutAt' => $this->waterShutAt,
                'daysLeft' => $this->daysLeft(false),
            ],
        ];
    }

    /** @param array<string, mixed> $utility */
    private static function shutAt(array $utility): ?int
    {
        return is_numeric($utility['shutAt'] ?? null) ? (int) $utility['shutAt'] : null;
    }
}
