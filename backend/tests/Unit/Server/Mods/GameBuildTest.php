<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Mods;

use App\Server\Mods\GameBuild;
use App\Server\Mods\WorkshopItem;
use PHPUnit\Framework\TestCase;

/**
 * Which mods belong on a server of a given build.
 *
 * The workshop tags at build granularity and no finer, so this is about
 * keeping a B41 mod off a B42 server -- not about matching 42.20.1.
 */
final class GameBuildTest extends TestCase
{
    public function testReadsTheBuildFromAFullVersionNumber(): void
    {
        self::assertSame('42', GameBuild::of('42.20.1')->number);
        self::assertSame('42', GameBuild::of('42.20')->number);
        self::assertSame('42', GameBuild::of('42')->number);
    }

    public function testReadsTheBuildFromAWorkshopStyleLabel(): void
    {
        self::assertSame('41', GameBuild::of('Build 41')->number);
    }

    public function testAnUnreadableVersionIsUnknownRatherThanWrong(): void
    {
        self::assertFalse(GameBuild::of('unreleased')->isKnown());
        self::assertFalse(GameBuild::of(null)->isKnown());
    }

    public function testTheTagMatchesWhatTheWorkshopUses(): void
    {
        self::assertSame('Build 42', GameBuild::of('42.20')->tag());
        self::assertNull(GameBuild::unknown()->tag());
    }

    public function testAModTaggedForThisBuildIsShown(): void
    {
        self::assertTrue(GameBuild::of('42.20')->accepts(self::item(['Build 42', 'Textures'])));
    }

    public function testAModTaggedForAnotherBuildIsNotShown(): void
    {
        self::assertFalse(GameBuild::of('42.20')->accepts(self::item(['Build 41'])));
    }

    /**
     * The case a single-value reading gets wrong: the workshop shows
     * plenty of mods carrying both, and they work on both.
     */
    public function testAModTaggedForSeveralBuildsIsShownOnEachOfThem(): void
    {
        $item = self::item(['Build 41', 'Build 42', 'Interface']);

        self::assertTrue(GameBuild::of('42.20')->accepts($item));
        self::assertTrue(GameBuild::of('41.78')->accepts($item));
        self::assertFalse(GameBuild::of('42.20')->conflictsWith($item));
    }

    /**
     * Hiding these would make the browser look broken: a small mod
     * often carries no build tag at all and works regardless.
     */
    public function testAModDeclaringNoBuildIsShownAndWarnsAboutNothing(): void
    {
        $item = self::item(['Textures']);

        self::assertTrue(GameBuild::of('42.20')->accepts($item));
        self::assertFalse(GameBuild::of('42.20')->conflictsWith($item));
    }

    public function testAMismatchIsWorthAWarning(): void
    {
        self::assertTrue(GameBuild::of('42.20')->conflictsWith(self::item(['Build 41'])));
    }

    /**
     * A filter applied on a guess would hide mods that are fine, so an
     * unknown build shows everything and warns about nothing.
     */
    public function testAnUnknownServerBuildFiltersNothingAndWarnsAboutNothing(): void
    {
        $build = GameBuild::unknown();

        self::assertTrue($build->accepts(self::item(['Build 41'])));
        self::assertFalse($build->conflictsWith(self::item(['Build 41'])));
    }

    /** @param list<string> $tags */
    private static function item(array $tags): WorkshopItem
    {
        return new WorkshopItem(
            workshopId: '1',
            title: 'A mod',
            description: '',
            previewUrl: null,
            tags: $tags,
            fileSize: 0,
            createdAt: null,
            updatedAt: null,
            subscriptions: 0,
            favourites: 0,
            views: 0,
        );
    }
}
