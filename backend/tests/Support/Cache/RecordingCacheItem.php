<?php

declare(strict_types=1);

namespace App\Tests\Support\Cache;

use Psr\Cache\CacheItemInterface;

/** Passes everything through, noting the lifetime on the way. */
final class RecordingCacheItem implements CacheItemInterface
{
    public function __construct(
        private readonly CacheItemInterface $item,
        private readonly RecordingCache $pool,
    ) {
    }

    public function inner(): CacheItemInterface
    {
        return $this->item;
    }

    public function getKey(): string
    {
        return $this->item->getKey();
    }

    public function get(): mixed
    {
        return $this->item->get();
    }

    public function isHit(): bool
    {
        return $this->item->isHit();
    }

    public function set(mixed $value): static
    {
        $this->item->set($value);

        return $this;
    }

    public function expiresAt(?\DateTimeInterface $expiration): static
    {
        $this->item->expiresAt($expiration);

        return $this;
    }

    public function expiresAfter(int|\DateInterval|null $time): static
    {
        $this->pool->record(\is_int($time) ? $time : null);
        $this->item->expiresAfter($time);

        return $this;
    }
}
