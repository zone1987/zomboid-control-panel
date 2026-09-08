<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Vehicles;

use App\Entity\FtpConfig;
use App\Entity\GameServer;
use App\Server\Storage\FileBrowserInterface;
use App\Server\Storage\StorageException;
use App\Server\Vehicles\Models\VehicleNames;
use App\Server\Vehicles\Models\VehicleTranslations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The 213 vehicle names were compiled in as English only, so a Polish
 * operator read "Ambulance" where the game says "Karetka". The game
 * ships them per language under IGUI_VehicleName* in IG_UI.json.
 */
final class VehicleTranslationsTest extends TestCase
{
    public function testReadsTheNamesTheGameShipsForThatLanguage(): void
    {
        $names = $this->translationsFor([
            'IGUI_VehicleNameAmbulanceBurnt' => 'Karetka',
            'IGUI_VehicleNameCarNormal' => 'Chevalier Nyala',
            'IGUI_SomethingElse' => 'Not a vehicle',
        ])->forLanguage($this->server(), 'pl');

        self::assertSame('Karetka', $names['AmbulanceBurnt']);
        self::assertArrayNotHasKey('SomethingElse', $names);
    }

    public function testAcceptsTheOlderNestedShape(): void
    {
        $names = $this->translationsFor(
            ['IG_UI' => ['IGUI_VehicleNameCarNormal' => 'Шевалье Ниала']],
        )->forLanguage($this->server(), 'ru');

        self::assertSame('Шевалье Ниала', $names['CarNormal']);
    }

    /**
     * "Verbrannt %1" is the game filling in another vehicle's name. The
     * panel has nothing to fill it with, so showing it would put a raw
     * %1 on screen; the English name stands instead.
     */
    public function testSkipsANameThatWantsAnArgument(): void
    {
        $names = $this->translationsFor([
            'IGUI_VehicleNameBurntCar' => 'Verbrannt %1',
            'IGUI_VehicleNameCarNormal' => 'Chevalier Nyala',
        ])->forLanguage($this->server(), 'de');

        self::assertArrayNotHasKey('BurntCar', $names);
        self::assertArrayHasKey('CarNormal', $names);
    }

    public function testFallsBackToTheEnglishNameWhenTheGameHasNone(): void
    {
        self::assertSame(
            VehicleNames::of('Base.CarNormal'),
            VehicleTranslations::pick([], 'Base.CarNormal'),
        );
    }

    public function testPrefersTheTranslationOverTheCompiledName(): void
    {
        self::assertSame(
            'Karetka',
            VehicleTranslations::pick(['AmbulanceBurnt' => 'Karetka'], 'Base.AmbulanceBurnt'),
        );
    }

    /** An installation without that language must not break the page. */
    public function testAnswersEmptyWhenTheFileIsNotThere(): void
    {
        $files = $this->createStub(FileBrowserInterface::class);
        $files->method('readTail')->willThrowException(new StorageException('files.missing', 'not found'));

        $translations = new VehicleTranslations($files, new ArrayAdapter());

        self::assertSame([], $translations->forLanguage($this->server(), 'it'));
    }

    public function testAnswersEmptyForALanguageCodeNobodyCouldHave(): void
    {
        $files = $this->createMock(FileBrowserInterface::class);
        $files->expects(self::never())->method('readTail');

        $translations = new VehicleTranslations($files, new ArrayAdapter());

        self::assertSame([], $translations->forLanguage($this->server(), '../../etc'));
    }

    public function testReadsTheFileOnceAndThenTheCache(): void
    {
        $files = $this->createMock(FileBrowserInterface::class);
        $files->expects(self::once())
            ->method('readTail')
            ->willReturn(json_encode(['IGUI_VehicleNameCarNormal' => 'Nyala'], \JSON_THROW_ON_ERROR));

        $translations = new VehicleTranslations($files, new ArrayAdapter());
        $server = $this->server();

        $translations->forLanguage($server, 'fr');
        $second = $translations->forLanguage($server, 'fr');

        self::assertSame('Nyala', $second['CarNormal']);
    }

    public function testRenamesTheBodyTileAlongWithItsVehicles(): void
    {
        $catalogue = VehicleTranslations::rename(
            [
                'items' => [
                    ['script' => 'Base.CarNormal', 'name' => 'Chevalier Nyala', 'body' => 'nyala'],
                    ['script' => 'Base.CarLightsPolice', 'name' => 'Police Chevalier Nyala', 'body' => 'nyala'],
                ],
                'bodies' => [['id' => 'nyala', 'name' => 'Chevalier Nyala']],
            ],
            ['CarNormal' => 'Шевалье Ньяла', 'CarLightsPolice' => 'Шевалье Ньяла - Полиция'],
        );

        self::assertSame('Шевалье Ньяла', $catalogue['items'][0]['name']);
        self::assertSame('Шевалье Ньяла - Полиция', $catalogue['items'][1]['name']);
        self::assertSame('Шевалье Ньяла', $catalogue['bodies'][0]['name']);
    }

    /**
     * Two masks can share a model name, and the catalogue tells them
     * apart with the mask's own file name. That qualifier is an
     * identifier, so only the name in front of it is translated.
     */
    public function testKeepsTheQualifierThatTellsTwoBodiesApart(): void
    {
        $catalogue = VehicleTranslations::rename(
            [
                'items' => [['script' => 'Base.Van', 'name' => 'Franklin Valuline', 'body' => 'van']],
                'bodies' => [['id' => 'van', 'name' => 'Franklin Valuline — van']],
            ],
            ['Van' => 'Франклин Валулайн'],
        );

        self::assertSame('Франклин Валулайн — van', $catalogue['bodies'][0]['name']);
    }

    /** The wrecks group carries no name; the frontend labels that one. */
    public function testLeavesABodyTheGameDoesNotName(): void
    {
        $catalogue = VehicleTranslations::rename(
            [
                'items' => [['script' => 'Base.CarNormal', 'name' => 'Chevalier Nyala', 'body' => 'wrecks']],
                'bodies' => [['id' => 'wrecks', 'name' => '']],
            ],
            ['CarNormal' => 'Шевалье Ньяла'],
        );

        self::assertSame('', $catalogue['bodies'][0]['name']);
    }

    public function testHandsBackTheCatalogueUntouchedWithoutTranslations(): void
    {
        $catalogue = ['items' => [['script' => 'Base.CarNormal', 'name' => 'Chevalier Nyala']]];

        self::assertSame($catalogue, VehicleTranslations::rename($catalogue, []));
    }

    /** @param array<string, mixed> $payload */
    private function translationsFor(array $payload): VehicleTranslations
    {
        $files = $this->createStub(FileBrowserInterface::class);
        $files->method('readTail')->willReturn(json_encode($payload, \JSON_THROW_ON_ERROR));

        return new VehicleTranslations($files, new ArrayAdapter());
    }

    private function server(): GameServer
    {
        $server = new GameServer('Test');

        // The constructor attaches itself to the server.
        new FtpConfig($server, 'localhost', 'user');

        return $server;
    }
}
