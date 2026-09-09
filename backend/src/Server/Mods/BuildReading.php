<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * The build in use, with both sources kept apart.
 *
 * Keeping them separate is the point: "the server says 42 but you typed
 * 41" is worth telling somebody, and a single merged value could not.
 */
final readonly class BuildReading
{
    public function __construct(
        public GameBuild $build,
        /** What the running game reported, when the bridge is new enough. */
        public ?string $reported,
        /** What an operator typed on the server page. */
        public ?string $entered,
        /** The whole version string, for display rather than filtering. */
        public ?string $fullVersion,
    ) {
    }

    /** Whether the typed value contradicts what the game reports. */
    public function disagrees(): bool
    {
        return $this->reported !== null
            && $this->entered !== null
            && GameBuild::of($this->entered)->number !== GameBuild::of($this->reported)->number;
    }

    public function source(): string
    {
        return match (true) {
            $this->reported !== null => 'bridge',
            $this->entered !== null => 'entered',
            default => 'unknown',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'build' => $this->build->number,
            'source' => $this->source(),
            'reported' => $this->reported,
            'entered' => $this->entered,
            'fullVersion' => $this->fullVersion,
            'disagrees' => $this->disagrees(),
        ];
    }
}
