<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * One workshop item as Steam describes it.
 *
 * The mod ids that `Mods=` needs are deliberately absent: they live in
 * the mod's own info.txt on the server and are read separately, so a
 * value here could only ever be a guess.
 */
final readonly class WorkshopItem
{
    /**
     * @param list<string> $tags
     * @param list<string> $dependencies workshop ids this item declares
     */
    public function __construct(
        public string $workshopId,
        public string $title,
        public string $description,
        public ?string $previewUrl,
        public array $tags,
        public int $fileSize,
        public ?\DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $updatedAt,
        public int $subscriptions,
        public int $favourites,
        public int $views,
        public array $dependencies = [],
        public bool $isCollection = false,
        public ?string $creatorSteamId = null,
    ) {
    }

    /** The workshop page, which is where comments and discussions stay. */
    public function url(): string
    {
        return 'https://steamcommunity.com/sharedfiles/filedetails/?id='.$this->workshopId;
    }

    /**
     * Which game build the author declared, if any.
     *
     * Null rather than a guess: plenty of items carry no build tag, and
     * warning about a mismatch nobody declared would be noise.
     */
    public function declaredBuild(): ?string
    {
        foreach ($this->tags as $tag) {
            if (preg_match('/^Build (\d+)$/', $tag, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /** Map mods need a `Map=` entry as well, which is easy to forget. */
    public function isMap(): bool
    {
        return \in_array('Map', $this->tags, true);
    }
}
