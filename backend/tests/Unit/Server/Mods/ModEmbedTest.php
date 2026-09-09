<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Mods;

use App\Server\Mods\ModEmbed;
use App\Server\Mods\WorkshopItem;
use PHPUnit\Framework\TestCase;

/**
 * A mod as an embed: a line of text names one, an embed shows it.
 */
final class ModEmbedTest extends TestCase
{
    public function testTheTitleLinksToTheWorkshopPage(): void
    {
        $embed = ModEmbed::of(self::item());

        self::assertSame('Fitted Sheets', $embed['title']);
        self::assertSame(
            'https://steamcommunity.com/sharedfiles/filedetails/?id=123',
            $embed['url'],
        );
    }

    /**
     * Steam's own address, not the panel's: Discord fetches the image
     * from its own servers, and a local deployment is not reachable
     * from there (rule 10j).
     */
    public function testTheThumbnailPointsAtSteamRatherThanThePanel(): void
    {
        $embed = ModEmbed::of(self::item());

        self::assertSame('https://images.steamusercontent.com/x.jpg', $embed['thumbnail']['url']);
    }

    public function testShowsTheFactsAnOperatorWouldCheck(): void
    {
        $names = array_column(ModEmbed::of(self::item())['fields'], 'name');

        self::assertContains('Größe', $names);
        self::assertContains('Aktualisiert', $names);
        self::assertContains('Build', $names);
    }

    /**
     * Discord renders this as the reader's own local time, which is the
     * right answer for a channel spanning timezones.
     */
    public function testTheDateIsLeftForDiscordToRenderLocally(): void
    {
        $fields = ModEmbed::of(self::item())['fields'];
        $updated = array_values(array_filter($fields, static fn (array $f): bool => $f['name'] === 'Aktualisiert'));

        self::assertMatchesRegularExpression('/^<t:\d+:d>$/', $updated[0]['value']);
    }

    /** The build has a field of its own, so repeating it is noise. */
    public function testTheBuildTagsAreNotRepeatedAmongTheCategories(): void
    {
        $embed = ModEmbed::of(self::item(['Build 42', 'Textures', 'Items']));

        self::assertSame('Textures · Items', $embed['description']);
    }

    public function testAModWithNoCoverSimplyHasNoThumbnail(): void
    {
        $embed = ModEmbed::of(self::item(previewUrl: null));

        self::assertArrayNotHasKey('thumbnail', $embed);
    }

    /** Nothing to show is no embed, rather than an empty box. */
    public function testThereIsNoEmbedForAModThatCouldNotBeDescribed(): void
    {
        self::assertNull(ModEmbed::of(null));
    }

    /** Discord refuses a title past its limit outright. */
    public function testAVeryLongTitleIsTrimmedRatherThanRefused(): void
    {
        $embed = ModEmbed::of(self::item(title: str_repeat('a', 300)));

        self::assertLessThanOrEqual(256, mb_strlen($embed['title']));
    }

    /**
     * @param list<string> $tags
     */
    private static function item(
        array $tags = ['Build 42', 'Textures'],
        ?string $previewUrl = 'https://images.steamusercontent.com/x.jpg',
        string $title = 'Fitted Sheets',
    ): WorkshopItem {
        return new WorkshopItem(
            workshopId: '123',
            title: $title,
            description: '',
            previewUrl: $previewUrl,
            tags: $tags,
            fileSize: 1048576,
            createdAt: null,
            updatedAt: new \DateTimeImmutable('2026-09-09'),
            subscriptions: 0,
            favourites: 0,
            views: 0,
        );
    }
}
