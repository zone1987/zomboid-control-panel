<?php

declare(strict_types=1);

namespace App\Tests\Support\Cache;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * An in-memory pool that also remembers the lifetime it was asked for.
 *
 * A cached failure and a cached fact need different TTLs, and nothing
 * else can see which one was used: ArrayAdapter keeps its expiries
 * private with no getter.
 */
final class RecordingCache implements CacheItemPoolInterface
{
    /** Seconds passed to the last expiresAfter, null when none was. */
    public ?int $lifetime = null;

    /** @var list<int|null> */
    public array $lifetimes = [];

    private ArrayAdapter $inner;

    public function __construct()
    {
        $this->inner = new ArrayAdapter();
    }

    public function getItem(string $key): CacheItemInterface
    {
        return new RecordingCacheItem($this->inner->getItem($key), $this);
    }

    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return $this->inner->hasItem($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function deleteItem(string $key): bool
    {
        return $this->inner->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->inner->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->inner->save($item instanceof RecordingCacheItem ? $item->inner() : $item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->inner->saveDeferred($item instanceof RecordingCacheItem ? $item->inner() : $item);
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }

    public function record(?int $seconds): void
    {
        $this->lifetime = $seconds;
        $this->lifetimes[] = $seconds;
    }
}
