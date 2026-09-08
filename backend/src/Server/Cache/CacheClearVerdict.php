<?php

declare(strict_types=1);

namespace App\Server\Cache;

/** What a cache clear actually did, including the ways it did not. */
final readonly class CacheClearVerdict
{
    public const CLEARED = 'cleared';
    public const PARTIAL = 'partiallyCleared';
    public const NOTHING = 'nothingToClear';
    public const FAILED = 'failed';

    private function __construct(
        public string $state,
        public int $keys,
        public int $servers,
        public ?string $detail,
    ) {
    }

    public static function cleared(int $keys, int $servers): self
    {
        return new self(self::CLEARED, $keys, $servers, null);
    }

    /** The pool reported that not everything went; the operator has to know. */
    public static function partiallyCleared(int $keys, int $servers): self
    {
        return new self(self::PARTIAL, $keys, $servers, null);
    }

    public static function nothingToClear(): self
    {
        return new self(self::NOTHING, 0, 0, null);
    }

    public static function failed(string $detail): self
    {
        return new self(self::FAILED, 0, 0, $detail);
    }

    public function succeeded(): bool
    {
        return $this->state === self::CLEARED || $this->state === self::NOTHING;
    }

    /** @return array{state: string, keys: int, servers: int, detail: string|null} */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'keys' => $this->keys,
            'servers' => $this->servers,
            'detail' => $this->detail,
        ];
    }
}
