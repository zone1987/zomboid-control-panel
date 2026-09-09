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

    public function testBuildsATreeTheInterfaceCanDraw(): void
    {
        $graph = new DependencyGraph($this->workshop([
            'A' => ['B', 'C'], 'B' => ['D'], 'C' => [], 'D' => [],
        ]));

        $tree = $graph->tree('A');
        $root = $tree['nodes'][0];

        self::assertSame('A', $root['workshopId']);
        self::assertCount(2, $root['children']);
        self::assertSame('D', $root['children'][0]['children'][0]['workshopId']);
    }

    /**
     * Expanding a mod already above it in the branch is what a circle
     * does. It is marked and left closed instead of drawn forever.
     */
    public function testMarksARepeatRatherThanExpandingItAgain(): void
    {
        $graph = new DependencyGraph($this->workshop(['A' => ['B'], 'B' => ['A']]));

        $tree = $graph->tree('A');
        $repeat = $tree['nodes'][0]['children'][0]['children'][0];

        self::assertSame('A', $repeat['workshopId']);
        self::assertTrue($repeat['repeats']);
        self::assertSame([], $repeat['children']);
    }

    /**
     * A mod the workshop cannot describe is still a requirement, so it
     * appears in the tree saying so rather than being dropped.
     */
    public function testKeepsARequirementTheWorkshopCouldNotDescribe(): void
    {
        $graph = new DependencyGraph($this->workshop(['A' => ['Gone']]));

        $child = $graph->tree('A')['nodes'][0]['children'][0];

        self::assertSame('Gone', $child['workshopId']);
        self::assertFalse($child['resolved']);
    }

    public function testATreeSaysSoWhenTheWalkWasCutShort(): void
    {
        $graph = new DependencyGraph($this->workshop([
            'A' => ['B'], 'B' => ['C'], 'C' => ['D'],
            'D' => ['E'], 'E' => ['F'], 'F' => [],
        ]));

        self::assertTrue($graph->tree('A')['truncated']);
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

            // The walk reads this one, because it is the only endpoint
            // that carries `children` at all.
            public function details(string $workshopId): WorkshopResult
            {
                return WorkshopResult::failed(WorkshopState::Unreachable);
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
