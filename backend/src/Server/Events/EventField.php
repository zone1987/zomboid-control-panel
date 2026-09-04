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
    ) {
    }

    public static function number(string $name, float $min, float $max, float|int $default): self
    {
        return new self($name, self::TYPE_NUMBER, min: $min, max: $max, default: $default);
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
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
