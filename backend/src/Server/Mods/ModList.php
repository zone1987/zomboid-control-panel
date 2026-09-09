<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * The three INI fields that together describe a server's mods.
 *
 * They are semicolon-separated lists the game reads positionally, so
 * order is meaning rather than presentation: `Mods=` decides load
 * order, and in `Map=` the first entry wins.
 */
final readonly class ModList
{
    public const WORKSHOP_KEY = 'WorkshopItems';
    public const MODS_KEY = 'Mods';
    public const MAP_KEY = 'Map';

    /**
     * @param list<string> $workshopIds
     * @param list<string> $modIds
     * @param list<string> $maps
     */
    public function __construct(
        public array $workshopIds = [],
        public array $modIds = [],
        public array $maps = [],
    ) {
    }

    /**
     * Splits one INI value.
     *
     * Blank entries are dropped rather than kept: `a;;b` and a trailing
     * semicolon are both common in hand-edited files and mean nothing.
     *
     * @return list<string>
     */
    public static function split(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $parts = [];

        foreach (explode(';', $value) as $part) {
            $trimmed = trim($part);

            if ($trimmed !== '') {
                $parts[] = $trimmed;
            }
        }

        return $parts;
    }

    /**
     * @param list<string> $values
     */
    public static function join(array $values): string
    {
        return implode(';', $values);
    }

    public function withWorkshopId(string $id): self
    {
        return new self(self::append($this->workshopIds, $id), $this->modIds, $this->maps);
    }

    public function withoutWorkshopId(string $id): self
    {
        return new self(self::remove($this->workshopIds, $id), $this->modIds, $this->maps);
    }

    public function withModIds(array $ids): self
    {
        $next = $this->modIds;

        foreach ($ids as $id) {
            $next = self::append($next, $id);
        }

        return new self($this->workshopIds, $next, $this->maps);
    }

    public function withoutModIds(array $ids): self
    {
        $next = $this->modIds;

        foreach ($ids as $id) {
            $next = self::remove($next, $id);
        }

        return new self($this->workshopIds, $next, $this->maps);
    }

    public function withMap(string $name): self
    {
        return new self($this->workshopIds, $this->modIds, self::append($this->maps, $name));
    }

    public function withoutMap(string $name): self
    {
        return new self($this->workshopIds, $this->modIds, self::remove($this->maps, $name));
    }

    /**
     * @return array<string, string> the changed INI keys and their values
     */
    public function toChanges(): array
    {
        return [
            self::WORKSHOP_KEY => self::join($this->workshopIds),
            self::MODS_KEY => self::join($this->modIds),
            self::MAP_KEY => self::join($this->maps),
        ];
    }

    public function hasWorkshopId(string $id): bool
    {
        return \in_array($id, $this->workshopIds, true);
    }

    /**
     * The same id without leading slashes, when that makes it a real one.
     *
     * `Mods=\PZ_Map` was found on a live server. The game looks a mod id
     * up in a map keyed by the `id=` line of its mod.info — an exact
     * match, not a path — so a leading slash simply finds nothing and
     * the mod never loads. Checked in `ZomboidFileSystem.getModDir`.
     *
     * Null when trimming changes nothing or leaves nothing, so a caller
     * can tell "this is fixable" from "this is just wrong".
     */
    public static function withoutLeadingSlashes(string $modId): ?string
    {
        $trimmed = ltrim($modId, '\\/');

        return $trimmed !== '' && $trimmed !== $modId ? $trimmed : null;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function append(array $values, string $value): array
    {
        if ($value === '' || \in_array($value, $values, true)) {
            return $values;
        }

        $values[] = $value;

        return $values;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function remove(array $values, string $value): array
    {
        return array_values(array_filter($values, static fn (string $held): bool => $held !== $value));
    }
}
