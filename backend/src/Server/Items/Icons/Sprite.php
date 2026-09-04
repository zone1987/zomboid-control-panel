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

    /** Item icons are the only ones the panel has a use for. */
    public function isItemIcon(): bool
    {
        return str_starts_with($this->name, 'Item_');
    }
}
