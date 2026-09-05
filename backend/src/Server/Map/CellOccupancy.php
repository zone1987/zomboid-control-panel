<?php

declare(strict_types=1);

namespace App\Server\Map;

/**
 * Which floors of one cell hold anything, and where.
 *
 * The mask has a bit per block, in the renderer's own order --
 * `bx * 32 + by`, matching Cell::get_square -- so a floor can be
 * skipped outright, and a partly built floor still says which part.
 */
final readonly class CellOccupancy
{
    /**
     * @param array<int, string> $masks    floor => bitmask, one bit a block
     * @param array<int, int>    $occupied floor => blocks holding anything
     */
    private function __construct(
        public int $minFloor,
        public int $maxFloor,
        private array $masks,
        private array $occupied,
        public int $blocksPerCell,
    ) {
    }

    /** @param array<string, mixed> $entry as the survey reports it */
    public static function fromSurvey(array $entry): self
    {
        $masks = [];
        $occupied = [];
        $blocks = 1024;

        /** @var array<string, array<string, mixed>> $floors */
        $floors = \is_array($entry['floors'] ?? null) ? $entry['floors'] : [];

        foreach ($floors as $floor => $detail) {
            $mask = base64_decode((string) ($detail['mask'] ?? ''), true);

            if ($mask === false) {
                continue;
            }

            $masks[(int) $floor] = $mask;
            $occupied[(int) $floor] = (int) ($detail['blocks'] ?? 0);
            $blocks = (int) ($detail['total'] ?? $blocks);
        }

        return new self(
            (int) ($entry['minlayer'] ?? 0),
            (int) ($entry['maxlayer'] ?? 1),
            $masks,
            $occupied,
            $blocks,
        );
    }

    /**
     * Whether this floor is worth rendering.
     *
     * False for a floor the cell does not have, and for one whose every
     * block came back empty -- a roof line with nothing on it.
     */
    public function hasContent(int $floor): bool
    {
        return ($this->occupied[$floor] ?? 0) > 0;
    }

    /** @return list<int> the floors worth drawing, in the cell's own order */
    public function floors(): array
    {
        $floors = array_keys(array_filter($this->occupied, static fn (int $blocks): bool => $blocks > 0));
        sort($floors);

        return array_values($floors);
    }

    public function blocksOn(int $floor): int
    {
        return $this->occupied[$floor] ?? 0;
    }

    /** Whether one block holds anything, for a finer decision later. */
    public function blockHasContent(int $floor, int $blockX, int $blockY): bool
    {
        $mask = $this->masks[$floor] ?? null;

        if ($mask === null) {
            return false;
        }

        $side = (int) sqrt($this->blocksPerCell);
        $index = $blockX * $side + $blockY;

        if ($index < 0 || $index >= $this->blocksPerCell) {
            return false;
        }

        $byte = \ord($mask[$index >> 3] ?? "\0");

        return ($byte & (1 << ($index & 7))) !== 0;
    }

    /** @param array<string, mixed> $entry as toArray() wrote it */
    public static function fromStorage(array $entry): self
    {
        $masks = [];
        $occupied = [];

        /** @var array<string, array<string, mixed>> $floors */
        $floors = \is_array($entry['floors'] ?? null) ? $entry['floors'] : [];

        foreach ($floors as $floor => $detail) {
            $mask = base64_decode((string) ($detail['mask'] ?? ''), true);

            if ($mask === false) {
                continue;
            }

            $masks[(int) $floor] = $mask;
            $occupied[(int) $floor] = (int) ($detail['blocks'] ?? 0);
        }

        return new self(
            (int) ($entry['minFloor'] ?? 0),
            (int) ($entry['maxFloor'] ?? 1),
            $masks,
            $occupied,
            (int) ($entry['blocks'] ?? 1024),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $floors = [];

        foreach ($this->floors() as $floor) {
            $floors[(string) $floor] = [
                'blocks' => $this->occupied[$floor],
                'mask' => base64_encode($this->masks[$floor]),
            ];
        }

        return [
            'minFloor' => $this->minFloor,
            'maxFloor' => $this->maxFloor,
            'blocks' => $this->blocksPerCell,
            'floors' => $floors,
        ];
    }
}
