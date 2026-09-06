<?php

declare(strict_types=1);

namespace App\Server\Bridge;

/**
 * The climate as the running game reports it.
 *
 * Bounds come from the game rather than a table here: every ClimateFloat
 * carries its own `getMin()`/`getMax()`, and three of the thirteen do not
 * run 0..1 — temperature is −80..80, the wind angle −1..1, view distance
 * 0..100. A panel with its own copy of those would be a second table to
 * keep in step, and `setAdminValue` clamps silently rather than
 * refusing, so being wrong would apply something other than what was
 * asked and report success.
 */
final readonly class ClimateReading
{
    /**
     * @param array<string, ClimateValue> $values      by the panel's own name
     * @param array{value: bool, admin: bool, adminValue: bool}|null $precipitationIsSnow
     */
    private function __construct(
        public array $values,
        public ?array $precipitationIsSnow,
        public float $windSpeedKph,
        public float $maxWindSpeedKph,
        public bool $snowing,
        public bool $raining,
        public bool $thunderStorming,
        public string $season,
        public float $seasonProgression,
    ) {
    }

    /**
     * @param array<string, mixed> $data the bridge's own answer
     *
     * @throws BridgeCommandFailed when the answer is not a climate reading
     */
    public static function fromBridge(array $data): self
    {
        if (!\is_array($data['values'] ?? null) || $data['values'] === []) {
            throw new BridgeCommandFailed(
                'The bridge answered without any climate values.',
                'bridge.badAnswer',
            );
        }

        $values = [];

        foreach ($data['values'] as $name => $value) {
            if (\is_string($name) && \is_array($value)) {
                $values[$name] = ClimateValue::fromBridge($name, $value);
            }
        }

        $snow = $data['precipitationIsSnow'] ?? null;

        return new self(
            values: $values,
            precipitationIsSnow: \is_array($snow) ? [
                'value' => ($snow['value'] ?? false) === true,
                'admin' => ($snow['admin'] ?? false) === true,
                'adminValue' => ($snow['adminValue'] ?? false) === true,
            ] : null,
            windSpeedKph: self::float($data, 'windSpeedKph'),
            // Never zero: dividing by the ceiling is how km/h becomes a
            // climate value, and the game's own answer is 120.
            maxWindSpeedKph: self::float($data, 'maxWindSpeedKph') ?: 120.0,
            snowing: ($data['snowing'] ?? false) === true,
            raining: ($data['raining'] ?? false) === true,
            thunderStorming: ($data['thunderStorming'] ?? false) === true,
            season: \is_string($data['season'] ?? null) ? $data['season'] : '',
            seasonProgression: self::float($data, 'seasonProgression'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'values' => array_map(
                static fn (ClimateValue $value): array => $value->toArray(),
                $this->values,
            ),
            'precipitationIsSnow' => $this->precipitationIsSnow,
            'windSpeedKph' => $this->windSpeedKph,
            'maxWindSpeedKph' => $this->maxWindSpeedKph,
            'snowing' => $this->snowing,
            'raining' => $this->raining,
            'thunderStorming' => $this->thunderStorming,
            'season' => $this->season,
            'seasonProgression' => $this->seasonProgression,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function float(array $data, string $name): float
    {
        return is_numeric($data[$name] ?? null) ? (float) $data[$name] : 0.0;
    }
}
