<?php

declare(strict_types=1);

namespace App\Server\Items\Icons;

/** One atlas: a PNG, and where each picture sits inside it. */
final readonly class SpritePage
{
    /** @param list<Sprite> $sprites */
    public function __construct(
        public string $name,
        public array $sprites,
        public string $png,
    ) {
    }
}
