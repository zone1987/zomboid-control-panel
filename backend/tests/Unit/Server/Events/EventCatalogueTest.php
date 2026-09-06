<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Events;

use App\Server\Events\EventAction;
use App\Server\Events\EventCatalogue;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue decides five pages, five nav entries and five routes, so
 * an action in a category nothing draws would simply vanish.
 */
final class EventCatalogueTest extends TestCase
{
    public function testEveryActionHasAKnownCategory(): void
    {
        foreach (EventCatalogue::all() as $action) {
            self::assertContains($action->category, EventAction::CATEGORIES, $action->id);
        }
    }

    /** A category with nothing in it is a page that opens onto nothing. */
    public function testEveryCategoryHoldsAtLeastOneAction(): void
    {
        $seen = [];

        foreach (EventCatalogue::all() as $action) {
            $seen[$action->category] = true;
        }

        foreach (EventAction::CATEGORIES as $category) {
            self::assertArrayHasKey($category, $seen, $category);
        }
    }

    public function testEveryActionHasAKnownChannel(): void
    {
        $channels = [
            EventAction::CHANNEL_RCON,
            EventAction::CHANNEL_BRIDGE,
            EventAction::CHANNEL_PREFERRED,
        ];

        foreach (EventCatalogue::all() as $action) {
            self::assertContains($action->channel, $channels, $action->id);
        }
    }

    public function testActionIdsAreUnique(): void
    {
        $ids = array_map(static fn (EventAction $a): string => $a->id, EventCatalogue::all());

        self::assertSame(\count($ids), \count(array_unique($ids)));
    }

    /**
     * Rain used to exist twice, once per channel, so an operator had to
     * know which button to press. One action, tried on the bridge first.
     */
    public function testRainIsOfferedOnceAndPrefersTheBridge(): void
    {
        $rain = array_values(array_filter(
            EventCatalogue::all(),
            static fn (EventAction $a): bool => str_contains(strtolower($a->id), 'rain'),
        ));

        self::assertCount(2, $rain, 'expected exactly startRain and stopRain');

        foreach ($rain as $action) {
            self::assertSame(EventAction::CHANNEL_PREFERRED, $action->channel, $action->id);
            self::assertNotSame([], $action->commands, $action->id.' needs an RCON command to fall back to');
        }
    }

    /**
     * Thunder is the rolling sound alone; lightning adds the flash. Proven
     * from the bytecode: both call
     * transmitServerTriggerLightning(x, y, doStrike, doLightning, doRumble),
     * and thunder passes (false, false, true) against lightning's
     * (false, true, true).
     */
    public function testThunderIsASoundAndLightningIsAnAction(): void
    {
        self::assertSame(
            EventAction::CATEGORY_SOUNDS,
            EventCatalogue::find('thunder')?->category,
        );
        self::assertSame(
            EventAction::CATEGORY_ACTIONS,
            EventCatalogue::find('lightning')?->category,
        );
    }

    /** The three that can wreck a server, and nothing else, sit together. */
    public function testOnlyTheZombieActionsAreDestructive(): void
    {
        foreach (EventCatalogue::all() as $action) {
            if ($action->destructive) {
                self::assertSame(EventAction::CATEGORY_ZOMBIES, $action->category, $action->id);
            }
        }
    }

    public function testABridgeActionNeedsNoRconCommand(): void
    {
        foreach (EventCatalogue::all() as $action) {
            if ($action->channel === EventAction::CHANNEL_BRIDGE) {
                self::assertSame([], $action->commands, $action->id);
            }
        }
    }
}
