<?php

declare(strict_types=1);

namespace App\Panel;

/**
 * How far the deployment the panel started has got.
 *
 * Coolify's `status` is a free string with no declared set of values,
 * so an unrecognised one is kept and reported rather than bent into a
 * state it might not be.
 */
final readonly class DeploymentStatus
{
    public const RUNNING = 'running';
    public const FINISHED = 'finished';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const UNKNOWN = 'unknown';
    public const NOT_FOUND = 'notFound';
    public const UNREACHABLE = 'unreachable';

    private function __construct(
        public string $state,
        public ?string $reported,
        public ?string $detail,
    ) {
    }

    /**
     * Coolify's own word for it, mapped to what the interface needs.
     *
     * `in_progress` and `queued` are both "still going" to a reader
     * waiting for the panel to come back.
     */
    public static function fromReported(string $reported): self
    {
        $normalised = strtolower(trim($reported));

        return match ($normalised) {
            'finished', 'success', 'succeeded' => new self(self::FINISHED, $reported, null),
            'failed', 'error' => new self(self::FAILED, $reported, null),
            'cancelled', 'canceled' => new self(self::CANCELLED, $reported, null),
            'queued', 'in_progress', 'in progress', 'running' => new self(self::RUNNING, $reported, null),
            // Not knowing is not the same as still running.
            default => new self(self::UNKNOWN, $reported, null),
        };
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, null, null);
    }

    public static function unreachable(string $detail): self
    {
        return new self(self::UNREACHABLE, null, $detail);
    }

    /** Whether the interface should keep asking. */
    public function isSettled(): bool
    {
        return \in_array(
            $this->state,
            [self::FINISHED, self::FAILED, self::CANCELLED, self::NOT_FOUND],
            true,
        );
    }

    public function succeeded(): bool
    {
        return $this->state === self::FINISHED;
    }

    /** @return array{state: string, reported: string|null, detail: string|null, settled: bool} */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'reported' => $this->reported,
            'detail' => $this->detail,
            'settled' => $this->isSettled(),
        ];
    }
}
