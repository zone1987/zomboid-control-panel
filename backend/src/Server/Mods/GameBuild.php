<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * Which game build a server runs, at the granularity the workshop uses.
 *
 * The workshop tags mods "Build 41" and "Build 42" and nothing finer,
 * so 42.20 and 42.9 are the same thing for filtering: the point is not
 * to match a version but to keep a B41 mod off a B42 server, which is
 * a common and silent way to break a start.
 *
 * Unknown is a real case and gets its own value rather than a null that
 * every caller would have to remember to check (rule 6c). An unknown
 * build shows everything and warns about nothing — a filter applied on
 * a guess would hide mods that are perfectly fine.
 */
final readonly class GameBuild
{
    private function __construct(public ?string $number)
    {
    }

    public static function of(string|int|null $version): self
    {
        if ($version === null) {
            return self::unknown();
        }

        // "42.20.1", "42.20", "Build 42", "42" all mean build 42.
        if (preg_match('/(\d+)/', (string) $version, $matches) !== 1) {
            return self::unknown();
        }

        return new self($matches[1]);
    }

    public static function unknown(): self
    {
        return new self(null);
    }

    public function isKnown(): bool
    {
        return $this->number !== null;
    }

    /** The workshop tag a server of this build should be filtered by. */
    public function tag(): ?string
    {
        return $this->number === null ? null : 'Build '.$this->number;
    }

    /**
     * Whether a mod may be shown for this server.
     *
     * A mod declaring no build at all is shown: plenty of small mods
     * carry no build tag and work anyway, and hiding them would make
     * the browser look broken.
     */
    public function accepts(WorkshopItem $item): bool
    {
        if (!$this->isKnown()) {
            return true;
        }

        $declared = $item->declaredBuilds();

        return $declared === [] || \in_array($this->number, $declared, true);
    }

    /**
     * Whether this mod contradicts the server's build.
     *
     * Distinct from `accepts`: a mod tagged for several builds including
     * this one is fine, and one tagged only for another is the warning
     * worth showing.
     */
    public function conflictsWith(WorkshopItem $item): bool
    {
        if (!$this->isKnown()) {
            return false;
        }

        $builds = $item->declaredBuilds();

        return $builds !== [] && !\in_array($this->number, $builds, true);
    }
}
