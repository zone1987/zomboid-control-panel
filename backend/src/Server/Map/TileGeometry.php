<?php

declare(strict_types=1);

namespace App\Server\Map;

/**
 * Which part of the world a Deep Zoom tile covers.
 *
 * Needed to render a missing tile on demand: the renderer works in
 * cells, the viewer asks for tiles, and one has to become the other.
 *
 * Nothing calls this today -- the tile route reads the object store and
 * answers 404 for a tile no run has drawn. Kept because the inversion
 * is the hard part and its tests caught a factor of two in it.
 *
 * The transform is pzmap2dzi's own, inverted. Its viewer computes
 *
 *     px = (x0 + (sx - sy) * sqr / 2) / scale
 *     py = (y0 + (sx + sy) * sqr / 4 - 1.5 * sqr * layer) / scale
 *
 * so a pixel maps back to a world square by solving for sx and sy.
 */
final readonly class TileGeometry
{
    /** Deep Zoom halves the image at every level below the deepest. */
    public function __construct(
        private int $originX,
        private int $originY,
        private int $squareSize,
        private int $scale,
        private int $tileSize,
        private int $deepestLevel,
        private int $cellSize = 256,
    ) {
    }

    /** @param array<string, mixed> $geometry as IsometricTiles reports it */
    public static function fromGeometry(array $geometry, int $tileSize, int $deepestLevel): self
    {
        return new self(
            originX: (int) ($geometry['originX'] ?? 0),
            originY: (int) ($geometry['originY'] ?? 0),
            squareSize: (int) ($geometry['squareSize'] ?? 128),
            scale: max(1, (int) ($geometry['scale'] ?? 1)),
            tileSize: $tileSize,
            deepestLevel: $deepestLevel,
            cellSize: (int) ($geometry['cellSize'] ?? 256),
        );
    }

    /**
     * The cells a tile touches, as [x, y] pairs.
     *
     * All four corners are converted rather than only one: an isometric
     * tile is a rotated square in world space, so it can straddle up to
     * four cells even when it is smaller than one.
     *
     * @return list<array{int, int}>
     */
    public function cellsUnder(int $level, int $column, int $row, int $floor = 0): array
    {
        // A level above the deepest covers proportionally more pixels.
        $span = 2 ** max(0, $this->deepestLevel - $level);
        $left = $column * $this->tileSize * $span;
        $top = $row * $this->tileSize * $span;
        $right = $left + $this->tileSize * $span;
        $bottom = $top + $this->tileSize * $span;

        $cells = [];

        foreach ([[$left, $top], [$right, $top], [$left, $bottom], [$right, $bottom]] as [$px, $py]) {
            [$sx, $sy] = $this->squareAt($px, $py, $floor);

            $cell = [intdiv((int) floor($sx), $this->cellSize), intdiv((int) floor($sy), $this->cellSize)];

            if (!\in_array($cell, $cells, true)) {
                $cells[] = $cell;
            }
        }

        // A tile spanning several cells needs the ones between its
        // corners too, not only the corners themselves.
        return $this->fill($cells);
    }

    /**
     * The world square at an image pixel.
     *
     * Solving the forward transform rather than reproducing it. Since
     * px = x0 + (sx - sy) * sqr/2 and py = y0 + (sx + sy) * sqr/4, the
     * difference and the sum of the two axes fall out directly, and
     * sx and sy follow from those. Dividing by sqr instead of by sqr/2
     * halves every result -- a round trip that came back at half the
     * coordinate is what showed it.
     *
     * @return array{float, float}
     */
    private function squareAt(float $px, float $py, int $floor): array
    {
        $difference = ($px * $this->scale - $this->originX) / ($this->squareSize / 2);
        $sum = ($py * $this->scale - $this->originY + 1.5 * $this->squareSize * $floor)
            / ($this->squareSize / 4);

        return [($sum + $difference) / 2, ($sum - $difference) / 2];
    }

    /**
     * @param list<array{int, int}> $corners
     *
     * @return list<array{int, int}>
     */
    private function fill(array $corners): array
    {
        $xs = array_column($corners, 0);
        $ys = array_column($corners, 1);

        $cells = [];

        for ($x = min($xs); $x <= max($xs); ++$x) {
            for ($y = min($ys); $y <= max($ys); ++$y) {
                $cells[] = [$x, $y];
            }
        }

        return $cells;
    }
}
