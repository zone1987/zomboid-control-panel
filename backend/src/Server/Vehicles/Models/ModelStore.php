<?php

declare(strict_types=1);

namespace App\Server\Vehicles\Models;

/**
 * The vehicle models and their textures, as the operator uploaded them.
 *
 * Outside the document root and out of git: the artwork is The Indie
 * Stone's, taken from the operator's own copy of the game, exactly as
 * the item icons are.
 *
 * A dedicated server does not ship these files -- they come from a game
 * installation, which is why they are uploaded rather than fetched.
 */
final readonly class ModelStore
{
    /** What a model file may be called, so a name can never leave the directory. */
    private const SAFE_NAME = '/^[A-Za-z0-9_.-]{1,150}$/';

    public function __construct(private string $directory)
    {
    }

    public function has(string $name): bool
    {
        $path = $this->pathOf($name);

        return $path !== null && is_file($path);
    }

    public function read(string $name): ?string
    {
        $path = $this->pathOf($name);

        if ($path === null || !is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function write(string $name, string $contents): void
    {
        $path = $this->pathOf($name);

        if ($path === null) {
            throw new \InvalidArgumentException('That is not a usable file name.');
        }

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o775, true);
        }

        file_put_contents($path, $contents);
    }

    /**
     * What is present, so the interface can say whether a vehicle can
     * be drawn before it tries.
     *
     * @return array{models: list<string>, textures: list<string>, bytes: int}
     */
    public function inventory(): array
    {
        $models = [];
        $textures = [];
        $bytes = 0;

        foreach ($this->files() as $file) {
            $bytes += (int) filesize($file);
            $name = basename($file);

            // A .txt here is the game's own mesh format, which the
            // wheels are the only thing shipped in.
            if (str_ends_with(strtolower($name), '.fbx') || str_ends_with(strtolower($name), '.txt')) {
                $models[] = $name;

                continue;
            }

            $textures[] = $name;
        }

        sort($models);
        sort($textures);

        return ['models' => $models, 'textures' => $textures, 'bytes' => $bytes];
    }

    public function delete(string $name): bool
    {
        $path = $this->pathOf($name);

        return $path !== null && is_file($path) && unlink($path);
    }

    /** @return list<string> */
    private function files(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $found = glob($this->directory.'/*');

        return $found === false ? [] : array_values(array_filter($found, 'is_file'));
    }

    /** Null for a name that is not plainly safe, rather than a cleaned-up guess. */
    private function pathOf(string $name): ?string
    {
        if (preg_match(self::SAFE_NAME, $name) !== 1 || str_contains($name, '..')) {
            return null;
        }

        return $this->directory.'/'.$name;
    }
}
