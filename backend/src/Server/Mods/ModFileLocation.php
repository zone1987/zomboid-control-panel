<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * Which file the mod list lives in, or why there is none to write.
 *
 * Three outcomes rather than a nullable path: "no credentials" and "the
 * directory holds two ini files" need different answers from the
 * operator, and collapsing them into null asks them to guess.
 */
final readonly class ModFileLocation
{
    private function __construct(
        public string $state,
        public ?string $path,
        public int $candidates,
    ) {
    }

    public static function found(string $path): self
    {
        return new self('found', $path, 1);
    }

    public static function noTransfer(): self
    {
        return new self('noTransfer', null, 0);
    }

    public static function noFile(): self
    {
        return new self('noFile', null, 0);
    }

    /** The operator has to say which one; picking silently edits the wrong server. */
    public static function ambiguous(int $candidates): self
    {
        return new self('ambiguous', null, $candidates);
    }

    public function isUsable(): bool
    {
        return $this->state === 'found' && $this->path !== null;
    }
}
