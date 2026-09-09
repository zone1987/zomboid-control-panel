<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Mods;

use App\Server\Mods\ManifestReader;
use PHPUnit\Framework\TestCase;

/**
 * Steam's own record of what it downloaded.
 *
 * The fixture below is the shape of a real `appworkshop_108600.acf`
 * read off the user's server, with one item edited to be out of date.
 */
final class ManifestReaderTest extends TestCase
{
    private const REAL = <<<'VDF'
        "AppWorkshop"
        {
            "appid"		"108600"
            "SizeOnDisk"		"27210928"
            "WorkshopItemsInstalled"
            {
                "2875848298"
                {
                    "size"		"3046026"
                    "timeupdated"		"1741365012"
                    "manifest"		"882019292731073492"
                }
                "3770149036"
                {
                    "size"		"24164902"
                    "timeupdated"		"1788687170"
                    "manifest"		"4342273948979859371"
                }
            }
            "WorkshopItemDetails"
            {
                "2875848298"
                {
                    "manifest"		"882019292731073492"
                    "timeupdated"		"1741365012"
                    "timetouched"		"1788938713"
                    "latest_timeupdated"		"1799000000"
                    "latest_manifest"		"999019292731073492"
                }
                "3770149036"
                {
                    "manifest"		"4342273948979859371"
                    "timeupdated"		"1788687170"
                    "timetouched"		"1788942539"
                    "latest_timeupdated"		"1788687170"
                    "latest_manifest"		"4342273948979859371"
                }
            }
        }
        VDF;

    public function testReadsWhatSteamHasDownloaded(): void
    {
        $manifest = ManifestReader::parse(self::REAL);

        self::assertSame('found', $manifest->state);
        self::assertSame(['2875848298', '3770149036'], $manifest->downloadedIds());
        self::assertSame(27210928, $manifest->sizeOnDisk);
    }

    /**
     * The whole point: what is on disk against what Steam has, measured
     * rather than remembered from a previous start.
     */
    public function testAnItemStreamHasNewerIsAnUpdate(): void
    {
        self::assertTrue(ManifestReader::parse(self::REAL)->hasUpdate('2875848298'));
    }

    public function testAnItemAtTheLatestVersionIsNotAnUpdate(): void
    {
        self::assertFalse(ManifestReader::parse(self::REAL)->hasUpdate('3770149036'));
    }

    /** Not knowing is not the same as being current (rule 6c). */
    public function testAnItemSteamHasNoRecordOfIsUnknownRatherThanCurrent(): void
    {
        self::assertNull(ManifestReader::parse(self::REAL)->hasUpdate('999999999'));
    }

    /**
     * Steam leaves `latest_timeupdated` at zero until it has checked,
     * and a zero compared as a number reads as "older than everything".
     */
    public function testAnUncheckedItemIsUnknownRatherThanOutOfDate(): void
    {
        $manifest = ManifestReader::parse(<<<'VDF'
            "AppWorkshop"
            {
                "WorkshopItemsInstalled"
                {
                    "111" { "size" "10" "timeupdated" "1700000000" }
                }
                "WorkshopItemDetails"
                {
                    "111" { "timeupdated" "1700000000" "latest_timeupdated" "0" }
                }
            }
            VDF);

        self::assertNull($manifest->hasUpdate('111'));
    }

    /**
     * The blocks nest, so a regex reaching for the first closing brace
     * would stop inside the first item rather than after the block.
     */
    public function testReadsBothBlocksDespiteTheirNesting(): void
    {
        $manifest = ManifestReader::parse(self::REAL);

        self::assertCount(2, $manifest->items);
        self::assertSame(3046026, $manifest->items['2875848298']['size']);
    }

    public function testAFileWithNothingInstalledReadsAsEmptyRatherThanBroken(): void
    {
        $manifest = ManifestReader::parse('"AppWorkshop" { "appid" "108600" }');

        self::assertSame('found', $manifest->state);
        self::assertSame([], $manifest->downloadedIds());
    }
}
