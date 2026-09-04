<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Events;

use App\Server\Events\EventAction;
use App\Server\Events\EventCatalogue;
use App\Server\Events\EventDispatcher;
use App\Server\Events\EventOutcome;
use App\Server\Rcon\RconCommandFailed;
use PHPUnit\Framework\TestCase;

/**
 * The argument shapes come from the command classes in build 42. Getting
 * one wrong does not raise an error in the game -- the command simply
 * does nothing -- so they are pinned here.
 */
final class EventDispatcherTest extends TestCase
{
    public function testRainCarriesItsIntensity(): void
    {
        self::assertSame('startrain 70', EventDispatcher::commandFor('startRain', ['intensity' => 70]));
    }

    public function testStoppingTheRainTakesNoArgument(): void
    {
        self::assertSame('stoprain', EventDispatcher::commandFor('stopRain', []));
    }

    public function testStormCarriesItsDurationInHours(): void
    {
        self::assertSame('startstorm 6', EventDispatcher::commandFor('startStorm', ['duration' => 6]));
    }

    /** Both take an optional username and fall back to a random player. */
    public function testThunderWithAndWithoutAPlayer(): void
    {
        self::assertSame('thunder', EventDispatcher::commandFor('thunder', []));
        self::assertSame('thunder "bob"', EventDispatcher::commandFor('thunder', ['player' => 'bob']));
    }

    public function testGunshotAndAlarmTakeNothing(): void
    {
        self::assertSame('gunshot', EventDispatcher::commandFor('gunshot', []));
        self::assertSame('alarm', EventDispatcher::commandFor('alarm', []));
    }

    /** createhorde2 is varargs: named flags, unquoted, in any order. */
    public function testAHordeAtAPointUsesTheFlagForm(): void
    {
        self::assertSame(
            'createhorde2 -count 30 -x 10778 -y 9770 -z 0 -radius 15',
            EventDispatcher::commandFor('hordeAtPoint', [
                'count' => 30,
                'x' => 10778,
                'y' => 9770,
                'radius' => 15,
            ]),
        );
    }

    public function testAHordeNearAPlayerTakesTheCountFirst(): void
    {
        self::assertSame(
            'createhorde 20 "bob"',
            EventDispatcher::commandFor('hordeNearPlayer', ['count' => 20, 'player' => 'bob']),
        );
    }

    public function testRemovingZombiesUsesTheFlagForm(): void
    {
        self::assertSame(
            'removezombies -x 100 -y 200 -z 0 -radius 50',
            EventDispatcher::commandFor('removeZombies', ['x' => 100, 'y' => 200, 'radius' => 50]),
        );
    }

    public function testRefusesANumberOutsideTheFieldsRange(): void
    {
        $this->expectException(RconCommandFailed::class);

        EventDispatcher::commandFor('startRain', ['intensity' => 400]);
    }

    public function testRefusesAHordeLargerThanTheServerAccepts(): void
    {
        $this->expectException(RconCommandFailed::class);

        EventDispatcher::commandFor('hordeAtPoint', ['count' => 900, 'x' => 1, 'y' => 1, 'radius' => 5]);
    }

    public function testFallsBackToTheFieldDefaultWhenAnInputIsMissing(): void
    {
        self::assertSame('startrain 50', EventDispatcher::commandFor('startRain', []));
    }

    public function testRefusesAVehicleScriptThatIsNotAName(): void
    {
        $this->expectException(RconCommandFailed::class);

        EventDispatcher::commandFor('spawnVehicle', ['script' => 'Base.Van" ; quit "', 'player' => 'bob']);
    }

    public function testSpawnsAVehicleBesideAPlayer(): void
    {
        self::assertSame(
            'addvehicle "Base.Van" "bob"',
            EventDispatcher::commandFor('spawnVehicle', ['script' => 'Base.Van', 'player' => 'bob']),
        );
    }

    public function testABroadcastLosesItsQuotesRatherThanEndingTheArgument(): void
    {
        self::assertSame(
            "servermsg \"he said 'go'\"",
            EventDispatcher::commandFor('broadcast', ['message' => 'he said "go"']),
        );
    }

    public function testRefusesAnEmptyBroadcast(): void
    {
        $this->expectException(RconCommandFailed::class);

        EventDispatcher::commandFor('broadcast', ['message' => '   ']);
    }

    public function testRefusesAnActionThatNeedsAPlayerWithoutOne(): void
    {
        $this->expectException(RconCommandFailed::class);

        EventDispatcher::commandFor('hordeNearPlayer', ['count' => 10]);
    }

    /** A bridge action reaching the RCON dispatcher is a mistake. */
    public function testRefusesToBuildAnRconCommandForABridgeAction(): void
    {
        $this->expectException(RconCommandFailed::class);

        EventDispatcher::commandFor('setTime', ['hour' => 12]);
    }

    public function testRefusesAnUnknownAction(): void
    {
        $this->expectException(RconCommandFailed::class);

        EventDispatcher::commandFor('summonHelicopterGunship', []);
    }

    /** Every RCON action must build a command; a bridge action has none. */
    public function testEveryRconActionInTheCatalogueBuildsSomething(): void
    {
        foreach (EventCatalogue::all() as $action) {
            if ($action->channel !== EventAction::CHANNEL_RCON) {
                continue;
            }

            $inputs = [];

            foreach ($action->fields as $field) {
                $inputs[$field->name] = match ($field->type) {
                    'player' => 'bob',
                    'text' => 'a message',
                    'choice' => $field->choices[0] ?? '',
                    default => $field->default ?? 1,
                };
            }

            self::assertNotSame('', EventDispatcher::commandFor($action->id, $inputs), $action->id);
        }
    }

    public function testReadsTheServersWordingForARefusal(): void
    {
        self::assertTrue((new EventOutcome('thunder', 'thunder "x"', 'User "x" not found'))->failed());
        self::assertTrue((new EventOutcome('startRain', 'startrain 5', 'Error'))->failed());
        self::assertTrue((new EventOutcome('startRain', 'startrain 5', ''))->failed());
        self::assertFalse((new EventOutcome('startRain', 'startrain 5', 'Rain started'))->failed());
        self::assertFalse((new EventOutcome('broadcast', 'servermsg "hi"', 'Message sent.'))->failed());
    }
}
