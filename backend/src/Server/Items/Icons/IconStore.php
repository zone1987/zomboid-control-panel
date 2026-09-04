<?php

declare(strict_types=1);

namespace App\Server\Items\Icons;

/**
 * Where extracted icons live.
 *
 * Outside the document root and out of version control: these are the
 * game's own artwork, extracted from the operator's installation for
 * their own use.
 */
final readonly class IconStore
{
    public function __construct(private string $directory)
    {
    }

    public function put(string $name, string $png): void
    {
        $path = $this->pathFor($name);

        if ($path === null) {
            return;
        }

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o775, true);
        }

        file_put_contents($path, $png);
    }

    public function get(string $name): ?string
    {
        $path = $this->resolve($name);

        if ($path === null) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function has(string $name): bool
    {
        return $this->resolve($name) !== null;
    }

    /**
     * An item script names its icon "MashedHerbs" while the sprite in
     * the pack is called "Item_MashedHerbs". Both spellings resolve, so
     * neither side has to know about the other.
     */
    private function resolve(string $name): ?string
    {
        foreach ([$name, 'Item_'.$name] as $candidate) {
            $path = $this->pathFor($candidate);

            if ($path !== null && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function count(): int
    {
        return \count(glob($this->directory.'/*.png') ?: []);
    }

    public function clear(): void
    {
        foreach (glob($this->directory.'/*.png') ?: [] as $path) {
            unlink($path);
        }
    }

    /**
     * Sprite names come from a file the panel did not write, so a name
     * that could climb out of the directory is refused rather than
     * cleaned up.
     */
    private function pathFor(string $name): ?string
    {
        if (preg_match('/^[A-Za-z0-9_.-]{1,150}$/', $name) !== 1 || str_contains($name, '..')) {
            return null;
        }

        return $this->directory.'/'.$name.'.png';
    }
}
