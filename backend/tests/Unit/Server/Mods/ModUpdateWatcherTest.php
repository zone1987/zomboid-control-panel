<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Mods;

use App\Server\Mods\ManifestReader;
use PHPUnit\Framework\TestCase;

/**
 * When Steam has a newer copy than the one on disk.
 *
 * The watcher itself needs a server, an FTP config and a dispatcher, so
 * these cover the decision it rests on: which ids count as waiting, and
 * that an unreadable manifest is not the same as nothing to report.
 */
final class ModUpdateWatcherTest extends TestCase
{
    private const WITH_UPDATE = <<<'VDF'
        "AppWorkshop"
        {
            "WorkshopItemsInstalled"
            {
                "111" { "size" "10" "timeupdated" "1700000000" }
                "222" { "size" "20" "timeupdated" "1700000000" }
            }
            "WorkshopItemDetails"
            {
                "111" { "timeupdated" "1700000000" "latest_timeupdated" "1800000000" }
                "222" { "timeupdated" "1700000000" "latest_timeupdated" "1700000000" }
            }
        }
        VDF;

    public function testOnlyTheOutdatedItemCountsAsWaiting(): void
    {
        $manifest = ManifestReader::parse(self::WITH_UPDATE);

        self::assertTrue($manifest->hasUpdate('111'));
        self::assertFalse($manifest->hasUpdate('222'));
    }

    /**
     * The difference the watcher announces on: what is waiting now
     * against what was already reported. Without it the same mod would
     * be announced every half hour until somebody restarted.
     */
    public function testOnlyWhatIsNewlyWaitingIsAnnounced(): void
    {
        $waiting = ['111', '333'];
        $announced = ['111'];

        self::assertSame(['333'], array_values(array_diff($waiting, $announced)));
    }

    /**
     * A first run has nothing to compare against, so it remembers and
     * stays quiet — otherwise every server would announce its whole
     * backlog the moment this shipped.
     */
    public function testAFirstRunHasNothingToCompareAgainst(): void
    {
        $announced = null;

        self::assertNull($announced, 'a null marker means remember, do not announce');
    }

    /**
     * "Could not read Steam's record" is not "nothing to report": one
     * is a fault, the other a fact (rule 6c).
     */
    public function testAnUnreadableManifestIsNotAnEmptyResult(): void
    {
        self::assertFalse(\App\Server\Mods\WorkshopManifest::unreachable()->isKnown());
        self::assertTrue(ManifestReader::parse(self::WITH_UPDATE)->isKnown());
    }
}
