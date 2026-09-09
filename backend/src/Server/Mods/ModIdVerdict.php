<?php

declare(strict_types=1);

namespace App\Server\Mods;

/**
 * The mod ids a workshop item contains, or why they are unknown.
 *
 * These are what `Mods=` needs, and they exist only inside the
 * downloaded item — Steam's API does not carry them. So an empty answer
 * has several causes an operator would act on differently, and
 * collapsing them into an empty list would leave them guessing
 * (rule 6c, the ItemTranslations case).
 */
final readonly class ModIdVerdict
{
    /**
     * @param list<string> $ids  the values of `id=` in every mod.info found
     * @param list<string> $paths where each was read from, for the operator
     */
    private function __construct(
        public string $state,
        public array $ids = [],
        public array $paths = [],
        public ?string $versionMin = null,
    ) {
    }

    /**
     * @param list<string> $ids
     * @param list<string> $paths
     */
    public static function found(array $ids, array $paths, ?string $versionMin = null): self
    {
        return new self('found', $ids, $paths, $versionMin);
    }

    /**
     * The server has not downloaded this item yet.
     *
     * The commonest case by far, and a benign one: it resolves itself at
     * the next server start, so the interface says "after the next
     * restart" rather than reporting a fault.
     */
    public static function notDownloaded(): self
    {
        return new self('notDownloaded');
    }

    /** The item is on disk but holds no mod.info anywhere. */
    public static function noModInfo(): self
    {
        return new self('noModInfo');
    }

    /** Steam's own content directory is not where it should be. */
    public static function noWorkshopDirectory(): self
    {
        return new self('noWorkshopDirectory');
    }

    public static function noTransfer(): self
    {
        return new self('noTransfer');
    }

    /** The transfer failed — which is not the same as finding nothing. */
    public static function unreachable(): self
    {
        return new self('unreachable');
    }

    public function isKnown(): bool
    {
        return $this->state === 'found';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'ids' => $this->ids,
            'paths' => $this->paths,
            'versionMin' => $this->versionMin,
        ];
    }
}
