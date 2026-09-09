<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Mods;

use App\Server\Mods\LoadOrder;
use PHPUnit\Framework\TestCase;

/**
 * `Mods=` is read positionally, so what a mod needs must come before it.
 */
final class LoadOrderTest extends TestCase
{
    public function testPutsARequirementBeforeTheModThatNeedsIt(): void
    {
        $verdict = LoadOrder::sort(['A', 'B'], [['A', 'B']]);

        self::assertSame(['B', 'A'], $verdict->order);
        self::assertTrue($verdict->changed);
    }

    public function testFollowsAChainOfRequirements(): void
    {
        $verdict = LoadOrder::sort(['A', 'B', 'C'], [['A', 'B'], ['B', 'C']]);

        self::assertSame(['C', 'B', 'A'], $verdict->order);
    }

    /**
     * A list that is already right must come back untouched — not
     * shuffled into an equally valid but unfamiliar order.
     */
    public function testLeavesAnAlreadyCorrectListAloneAndSaysNothingChanged(): void
    {
        $verdict = LoadOrder::sort(['B', 'A'], [['A', 'B']]);

        self::assertSame(['B', 'A'], $verdict->order);
        self::assertFalse($verdict->changed);
    }

    public function testKeepsTheFilesOrderAmongModsThatDoNotDependOnEachOther(): void
    {
        $verdict = LoadOrder::sort(['C', 'A', 'B'], []);

        self::assertSame(['C', 'A', 'B'], $verdict->order);
        self::assertFalse($verdict->changed);
    }

    /**
     * Two mods requiring each other is publishable. Refusing and naming
     * them is useful; emitting some order anyway would not be.
     */
    public function testRefusesToSortACycleAndNamesTheModsInvolved(): void
    {
        $verdict = LoadOrder::sort(['A', 'B'], [['A', 'B'], ['B', 'A']]);

        self::assertSame('cycle', $verdict->state);
        self::assertFalse($verdict->succeeded());
        self::assertEqualsCanonicalizing(['A', 'B'], $verdict->tangled);
    }

    /** A mod requiring itself is a typo, not an unsolvable order. */
    public function testIgnoresAModThatRequiresItself(): void
    {
        $verdict = LoadOrder::sort(['A'], [['A', 'A']]);

        self::assertSame(['A'], $verdict->order);
    }

    /**
     * A requirement nobody installed cannot be ordered. It is reported
     * as missing elsewhere rather than quietly added to the file here.
     */
    public function testIgnoresARequirementThatIsNotInstalled(): void
    {
        $verdict = LoadOrder::sort(['A'], [['A', 'NotInstalled']]);

        self::assertSame(['A'], $verdict->order);
        self::assertFalse($verdict->changed);
    }

    public function testAnEmptyListSortsToAnEmptyList(): void
    {
        self::assertSame([], LoadOrder::sort([], [])->order);
    }

    public function testNamesEachModOnceEvenIfTheFileRepeatsIt(): void
    {
        self::assertSame(['A'], LoadOrder::sort(['A', 'A'], [])->order);
    }
}
