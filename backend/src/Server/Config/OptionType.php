<?php

declare(strict_types=1);

namespace App\Server\Config;

/**
 * The value shapes a configuration option can take.
 *
 * These are the game's own `ConfigOption::getType()` strings, so an
 * option's type is read from the class rather than inferred from the
 * value that happens to be in the file. `Enum` is an integer with named
 * choices, which is why it is not merely `Integer`: shown as a number it
 * is unusable.
 */
enum OptionType: string
{
    case Boolean = 'boolean';
    case Double = 'double';
    case Integer = 'integer';
    case Enum = 'enum';
    case String = 'string';
    case Text = 'text';

    /** Whether a value of this type is written to Lua without quotes. */
    public function isBare(): bool
    {
        return match ($this) {
            self::String, self::Text => false,
            default => true,
        };
    }
}
