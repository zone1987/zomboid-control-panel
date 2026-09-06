<?php

declare(strict_types=1);

namespace App\Server\Events;

/** One input an event action needs before it can run. */
final readonly class EventField
{
    public const TYPE_NUMBER = 'number';
    public const TYPE_PLAYER = 'player';
    public const TYPE_TEXT = 'text';
    public const TYPE_CHOICE = 'choice';
    public const TYPE_TOGGLE = 'toggle';

    /** The units a field can be stated in, so a range is never bare. */
    public const UNIT_PERCENT = '%';
    public const UNIT_KPH = 'km/h';
    public const UNIT_CELSIUS = '°C';
    public const UNIT_HOURS = 'h';
    public const UNIT_TILES = 'tiles';
    public const UNIT_COUNT = 'x';

    /** @param list<string> $choices */
    public function __construct(
        public string $name,
        public string $type,
        public bool $required = true,
        public ?float $min = null,
        public ?float $max = null,
        public float|int|string|bool|null $default = null,
        public array $choices = [],
        public ?int $maxLength = null,
        /** What the number means, printed after the value and the range. */
        public ?string $unit = null,
    ) {
    }

    public static function number(
        string $name,
        float $min,
        float $max,
        float|int $default,
        ?string $unit = null,
    ): self {
        return new self($name, self::TYPE_NUMBER, min: $min, max: $max, default: $default, unit: $unit);
    }

    public static function percent(string $name, float|int $default, float $min = 0, float $max = 100): self
    {
        return self::number($name, $min, $max, $default, self::UNIT_PERCENT);
    }

    public static function toggle(string $name, bool $default = false): self
    {
        return new self($name, self::TYPE_TOGGLE, default: $default);
    }

    public static function player(string $name, bool $required = true): self
    {
        return new self($name, self::TYPE_PLAYER, required: $required);
    }

    /** @param list<string> $choices */
    public static function choice(string $name, array $choices, ?string $default = null): self
    {
        return new self($name, self::TYPE_CHOICE, choices: $choices, default: $default ?? ($choices[0] ?? null));
    }

    public static function text(string $name, int $maxLength): self
    {
        return new self($name, self::TYPE_TEXT, maxLength: $maxLength);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required,
            'min' => $this->min,
            'max' => $this->max,
            'default' => $this->default,
            'choices' => $this->choices,
            'maxLength' => $this->maxLength,
            'unit' => $this->unit,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
