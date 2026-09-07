<?php

declare(strict_types=1);

namespace App\Server\Items\Icons;

/** One picture inside a page, given as a crop of it. */
final readonly class Sprite
{
    public function __construct(
        public string $name,
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {
    }

    /** @param list<string> $prefixes */
    public function hasAnyPrefix(array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($this->name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
