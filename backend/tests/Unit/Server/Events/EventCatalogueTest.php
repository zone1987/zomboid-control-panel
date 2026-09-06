<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Events;

use App\Server\Events\EventAction;
use App\Server\Events\EventCatalogue;
use App\Server\Events\EventField;
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

    /**
     * Snow is a type rather than an amount, so it is the first toggle in
     * the catalogue -- and the type has to reach the interface, which
     * renders a switch from it rather than a slider between two states.
     */
    public function testSnowIsOfferedAsAToggleThroughTheBridge(): void
    {
        $snow = EventCatalogue::find('setSnow');

        self::assertInstanceOf(EventAction::class, $snow);
        self::assertSame(EventAction::CATEGORY_WEATHER, $snow->category);
        self::assertSame(EventAction::CHANNEL_BRIDGE, $snow->channel);

        self::assertCount(1, $snow->fields);
        self::assertSame(EventField::TYPE_TOGGLE, $snow->fields[0]->type);
        self::assertSame('snowing', $snow->fields[0]->name);
    }

    /**
     * A toggle defaulting to false must survive toArray(), which filters
     * out empties: false is an answer, and a default the panel never
     * receives leaves the switch guessing.
     */
    public function testAToggleKeepsAFalseDefault(): void
    {
        self::assertSame(
            false,
            EventField::toggle('snowing')->toArray()['default'] ?? null,
        );
    }

    public function testTheBlizzardIsABridgeActionWithNoInputs(): void
    {
        $blizzard = EventCatalogue::find('startBlizzard');

        self::assertInstanceOf(EventAction::class, $blizzard);
        self::assertSame(EventAction::CHANNEL_BRIDGE, $blizzard->channel);
        self::assertSame([], $blizzard->fields);
    }

    /**
     * A range reading "0-120" beside one reading "0-100" says nothing
     * about which is km/h and which a percentage, so every bounded number
     * states its unit -- from the field, once, rather than each page
     * guessing it from the action's name.
     */
    public function testEveryBoundedNumberStatesItsUnit(): void
    {
        $units = [
            EventField::UNIT_PERCENT,
            EventField::UNIT_KPH,
            EventField::UNIT_CELSIUS,
            EventField::UNIT_HOURS,
            EventField::UNIT_TILES,
            EventField::UNIT_COUNT,
        ];

        foreach (EventCatalogue::all() as $action) {
            foreach ($action->fields as $field) {
                if ($field->type !== EventField::TYPE_NUMBER) {
                    continue;
                }

                // A coordinate and a volume are bare numbers: there is no
                // unit a reader would recognise for either.
                if (\in_array($field->name, ['x', 'y', 'z', 'volume', 'day', 'month'], true)) {
                    continue;
                }

                self::assertContains(
                    $field->unit,
                    $units,
                    sprintf('%s.%s states no unit', $action->id, $field->name),
                );
            }
        }
    }

    /** Wind is asked for in km/h, against the game's own ceiling. */
    public function testWindIsDeclaredInKilometresPerHour(): void
    {
        $wind = EventCatalogue::find('setWind');

        self::assertInstanceOf(EventAction::class, $wind);
        self::assertSame(EventField::UNIT_KPH, $wind->fields[0]->unit);
        self::assertSame((float) EventCatalogue::MAX_WIND_KPH, $wind->fields[0]->max);
    }
}
