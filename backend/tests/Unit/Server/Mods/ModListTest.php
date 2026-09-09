<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Mods;

use App\Server\Mods\ModList;
use PHPUnit\Framework\TestCase;

/**
 * The three INI fields a server reads to load its mods.
 *
 * Order is meaning here, not presentation, so every case below cares
 * about position as much as membership.
 */
final class ModListTest extends TestCase
{
    public function testSplitsASemicolonList(): void
    {
        self::assertSame(['514427485', '513111049'], ModList::split('514427485;513111049'));
    }

    public function testTreatsAnAbsentValueAsAnEmptyList(): void
    {
        self::assertSame([], ModList::split(null));
        self::assertSame([], ModList::split(''));
    }

    /**
     * Both shapes turn up in hand-edited files and neither names a mod.
     */
    public function testDropsBlankEntriesFromDoubledOrTrailingSemicolons(): void
    {
        self::assertSame(['a', 'b'], ModList::split('a;;b;'));
    }

    public function testTrimsSpacesAroundAnEntry(): void
    {
        self::assertSame(['a', 'b'], ModList::split(' a ; b '));
    }

    public function testKeepsTheOrderTheFileGave(): void
    {
        self::assertSame(['c', 'a', 'b'], ModList::split('c;a;b'));
    }

    public function testAddsAWorkshopIdAtTheEndSoExistingLoadOrderIsUndisturbed(): void
    {
        $list = new ModList(['1', '2']);

        self::assertSame(['1', '2', '3'], $list->withWorkshopId('3')->workshopIds);
    }

    /**
     * The game would download the same item twice and the second entry
     * changes nothing, so a duplicate is silently the same list.
     */
    public function testRefusesToAddAnIdItAlreadyHas(): void
    {
        $list = new ModList(['1', '2']);

        self::assertSame(['1', '2'], $list->withWorkshopId('2')->workshopIds);
    }

    public function testRemovingAnIdClosesTheGapRatherThanLeavingAHole(): void
    {
        $list = new ModList(['1', '2', '3']);

        self::assertSame(['1', '3'], $list->withoutWorkshopId('2')->workshopIds);
    }

    public function testRemovingSomethingAbsentLeavesTheListAlone(): void
    {
        $list = new ModList(['1', '2']);

        self::assertSame(['1', '2'], $list->withoutWorkshopId('9')->workshopIds);
    }

    /**
     * One workshop item can carry several mod ids, and all of them have
     * to reach `Mods=` or the item is downloaded but never loaded.
     */
    public function testAddsEveryModIdOfOneWorkshopItem(): void
    {
        $list = (new ModList())->withModIds(['ModA', 'ModB']);

        self::assertSame(['ModA', 'ModB'], $list->modIds);
    }

    public function testWritesAllThreeKeysSoARemovalIsNotMistakenForNoChange(): void
    {
        $list = new ModList(['1'], ['ModA'], ['Muldraugh, KY']);

        self::assertSame([
            'WorkshopItems' => '1',
            'Mods' => 'ModA',
            'Map' => 'Muldraugh, KY',
        ], $list->toChanges());
    }

    /**
     * Emptying a list has to write an empty value; omitting the key
     * would leave the removed mod loaded.
     */
    public function testAnEmptiedListIsWrittenAsAnEmptyValue(): void
    {
        $changes = (new ModList())->toChanges();

        self::assertSame('', $changes['WorkshopItems']);
        self::assertSame('', $changes['Mods']);
        self::assertSame('', $changes['Map']);
    }

    public function testAMapNameSurvivesItsComma(): void
    {
        self::assertSame(['Muldraugh, KY'], ModList::split('Muldraugh, KY'));
    }
}
