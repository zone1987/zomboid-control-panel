<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Mods;

use App\Server\Mods\DependencyGraph;
use App\Server\Mods\WorkshopItem;
use App\Server\Mods\WorkshopResult;
use App\Server\Mods\WorkshopSource;
use App\Server\Mods\WorkshopState;
use PHPUnit\Framework\TestCase;

/**
 * What a mod needs, followed through the chain.
 *
 * The input is published by strangers, so the two bounds — depth and
 * cycles — matter more than the happy path.
 */
final class DependencyGraphTest extends TestCase
{
    public function testFollowsAChainToItsEnd(): void
    {
        $graph = new DependencyGraph($this->workshop([
            'A' => ['B'],
            'B' => ['C'],
            'C' => [],
        ]));

        self::assertSame(['B', 'C'], $graph->resolve(['A'])->required);
    }

    /**
     * A requiring B requiring A is publishable, and a naive walk would
     * spin on it forever.
     */
    public function testACircularRequirementTerminates(): void
    {
        $graph = new DependencyGraph($this->workshop([
            'A' => ['B'],
            'B' => ['A'],
        ]));

        $resolution = $graph->resolve(['A']);

        self::assertTrue($resolution->succeeded());
        self::assertSame(['B'], $resolution->required);
    }

    public function testSaysSoWhenTheChainRanPastTheDepthLimit(): void
    {
        $graph = new DependencyGraph($this->workshop([
            'A' => ['B'], 'B' => ['C'], 'C' => ['D'],
            'D' => ['E'], 'E' => ['F'], 'F' => [],
        ]));

        self::assertTrue($graph->resolve(['A'])->truncated);
    }

    public function testAShortChainIsNotReportedAsTruncated(): void
    {
        $graph = new DependencyGraph($this->workshop(['A' => ['B'], 'B' => []]));

        self::assertFalse($graph->resolve(['A'])->truncated);
    }

    /**
     * The same field means two things: a collection's children are its
     * contents, so walking them would pull a whole curated list in as
     * though the mod required every part of it.
     */
    public function testDoesNotTreatACollectionsContentsAsRequirements(): void
    {
        $graph = new DependencyGraph($this->workshop(
            ['A' => ['B', 'C'], 'B' => [], 'C' => []],
            collections: ['A'],
        ));

        self::assertSame([], $graph->resolve(['A'])->required);
    }

    public function testNamesNothingTwiceWhenTwoModsNeedTheSameThing(): void
    {
        $graph = new DependencyGraph($this->workshop([
            'A' => ['Shared'], 'B' => ['Shared'], 'Shared' => [],
        ]));

        self::assertSame(['Shared'], $graph->resolve(['A', 'B'])->required);
    }

    /** Already-installed mods are not requirements to add. */
    public function testAnAlreadyInstalledRequirementIsNotMissing(): void
    {
        self::assertSame(['C'], DependencyGraph::missing(['A', 'B'], ['B', 'C']));
    }

    /**
     * A failed lookup must not read as "this mod needs nothing": one is
     * a fault, the other a fact.
     */
    public function testAFailedLookupIsNotAnEmptyRequirementSet(): void
    {
        $graph = new DependencyGraph(new class implements WorkshopSource {
            public function hasKey(): bool { return false; }

            public function itemsById(array $workshopIds): WorkshopResult
            {
                return WorkshopResult::failed(WorkshopState::Unreachable);
            }

            public function search(string $term = '', array $tags = [], string $sort = 'trend', int $page = 1, int $perPage = 30): WorkshopResult
            {
                return WorkshopResult::ok([]);
            }

            public function details(string $workshopId): WorkshopResult
            {
                return WorkshopResult::ok([]);
            }
        });

        $resolution = $graph->resolve(['A']);

        self::assertFalse($resolution->succeeded());
        self::assertSame(WorkshopState::Unreachable, $resolution->state);
    }

    /**
     * @param array<string, list<string>> $children
     * @param list<string>                $collections
     */
    private function workshop(array $children, array $collections = []): WorkshopSource
    {
        return new class($children, $collections) implements WorkshopSource {
            /**
             * @param array<string, list<string>> $children
             * @param list<string>                $collections
             */
            public function __construct(
                private readonly array $children,
                private readonly array $collections,
            ) {
            }

            public function hasKey(): bool
            {
                return true;
            }

            public function itemsById(array $workshopIds): WorkshopResult
            {
                $items = [];

                foreach ($workshopIds as $id) {
                    if (!\array_key_exists($id, $this->children)) {
                        continue;
                    }

                    $items[] = new WorkshopItem(
                        workshopId: $id,
                        title: $id,
                        description: '',
                        previewUrl: null,
                        tags: [],
                        fileSize: 0,
                        createdAt: null,
                        updatedAt: null,
                        subscriptions: 0,
                        favourites: 0,
                        views: 0,
                        dependencies: $this->children[$id],
                        isCollection: \in_array($id, $this->collections, true),
                    );
                }

                return WorkshopResult::ok($items);
            }

            public function search(string $term = '', array $tags = [], string $sort = 'trend', int $page = 1, int $perPage = 30): WorkshopResult
            {
                return WorkshopResult::ok([]);
            }

            public function details(string $workshopId): WorkshopResult
            {
                return $this->itemsById([$workshopId]);
            }
        };
    }
}
