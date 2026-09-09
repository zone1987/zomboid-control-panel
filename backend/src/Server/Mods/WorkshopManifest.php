<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * What Steam itself recorded about the items it downloaded.
 *
 * `appworkshop_108600.acf` is Valve's own bookkeeping, in the same VDF
 * format as `appmanifest_108600.acf`. It answers two questions nothing
 * else can:
 *
 * - **Is an update available?** `timeupdated` is what is on disk and
 *   `latest_timeupdated` is what Steam has. A difference is the answer,
 *   **measured rather than remembered** — the panel storing what it saw
 *   at the last start would be a guess by comparison.
 * - **What is actually downloaded?** Which is not the same as what
 *   `WorkshopItems=` asks for: Steam does not delete an item when it
 *   leaves the list.
 */
final readonly class WorkshopManifest
{
    /**
     * @param array<string, array{size: int, timeUpdated: int, latestTimeUpdated: int}> $items
     */
    private function __construct(
        public string $state,
        public array $items = [],
        public int $sizeOnDisk = 0,
    ) {
    }

    /**
     * @param array<string, array{size: int, timeUpdated: int, latestTimeUpdated: int}> $items
     */
    public static function read(array $items, int $sizeOnDisk): self
    {
        return new self('found', $items, $sizeOnDisk);
    }

    /** Steam has never downloaded anything here, which is not a fault. */
    public static function absent(): self
    {
        return new self('absent');
    }

    public static function unreachable(): self
    {
        return new self('unreachable');
    }

    public static function noTransfer(): self
    {
        return new self('noTransfer');
    }

    public function isKnown(): bool
    {
        return $this->state === 'found';
    }

    /**
     * Whether Steam has a newer copy than the one on disk.
     *
     * Null rather than false for an item it does not know: not knowing
     * is not the same as being current (rule 6c).
     */
    public function hasUpdate(string $workshopId): ?bool
    {
        $item = $this->items[$workshopId] ?? null;

        if ($item === null) {
            return null;
        }

        // Steam leaves latest at 0 until it has checked, and a zero
        // would otherwise read as "older than everything".
        if ($item['latestTimeUpdated'] <= 0) {
            return null;
        }

        return $item['latestTimeUpdated'] > $item['timeUpdated'];
    }

    /**
     * @return list<string> ids Steam downloaded, whatever the ini says
     */
    public function downloadedIds(): array
    {
        return array_map(strval(...), array_keys($this->items));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $items = [];

        foreach ($this->items as $id => $item) {
            // PHP turns a numeric array key into an int on its own, and
            // a workshop id is all digits -- the same silent coercion
            // that once lost a single id out of the ini.
            $id = (string) $id;

            $items[$id] = [
                'size' => $item['size'],
                'timeUpdated' => $item['timeUpdated'],
                'latestTimeUpdated' => $item['latestTimeUpdated'],
                'hasUpdate' => $this->hasUpdate($id),
            ];
        }

        return ['state' => $this->state, 'sizeOnDisk' => $this->sizeOnDisk, 'items' => $items];
    }
}
