<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Vehicles\Models;

use App\Server\Vehicles\Models\ModelStore;
use PHPUnit\Framework\TestCase;

final class ModelStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/vehicle-models-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function store(): ModelStore
    {
        return new ModelStore($this->directory);
    }

    public function testKeepsWhatWasUploadedAndReadsItBack(): void
    {
        $store = $this->store();
        $store->write('Vehicles_CarNormal.fbx', 'model bytes');

        self::assertTrue($store->has('Vehicles_CarNormal.fbx'));
        self::assertSame('model bytes', $store->read('Vehicles_CarNormal.fbx'));
    }

    public function testReportsNothingForAFileThatWasNeverUploaded(): void
    {
        self::assertFalse($this->store()->has('Vehicles_Missing.fbx'));
        self::assertNull($this->store()->read('Vehicles_Missing.fbx'));
    }

    public function testTellsModelsApartFromTextures(): void
    {
        $store = $this->store();
        $store->write('Vehicles_CarNormal.fbx', 'model');
        $store->write('vehicle_carnormalshell.png', 'texture');

        $inventory = $store->inventory();

        self::assertSame(['Vehicles_CarNormal.fbx'], $inventory['models']);
        self::assertSame(['vehicle_carnormalshell.png'], $inventory['textures']);
        self::assertSame(12, $inventory['bytes']);
    }

    /**
     * The wheels ship only in the game's own text mesh format, so a
     * .txt is a model here rather than something to ignore.
     */
    public function testCountsATextMeshAsAModel(): void
    {
        $store = $this->store();
        $store->write('Vehicles_Wheel.txt', '# Project Zomboid Mesh');

        $inventory = $store->inventory();

        self::assertSame(['Vehicles_Wheel.txt'], $inventory['models']);
        self::assertSame([], $inventory['textures']);
    }

    public function testAnEmptyStoreIsEmptyRatherThanAnError(): void
    {
        self::assertSame(
            ['models' => [], 'textures' => [], 'bytes' => 0],
            $this->store()->inventory(),
        );
    }

    /**
     * A name is refused outright rather than cleaned up: a sanitised
     * guess is how a traversal slips through.
     */
    public function testRefusesANameThatCouldLeaveTheDirectory(): void
    {
        $store = $this->store();

        foreach (['../secret.fbx', '..%2Fsecret', '/etc/passwd', 'a/../../b.fbx', ''] as $name) {
            self::assertFalse($store->has($name), sprintf('"%s" was accepted.', $name));
            self::assertNull($store->read($name), sprintf('"%s" was read.', $name));
        }
    }

    public function testRefusesToWriteUnderAnUnsafeName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store()->write('../escaped.fbx', 'nope');
    }

    public function testRemovesAFileTheOperatorNoLongerWants(): void
    {
        $store = $this->store();
        $store->write('Vehicles_CarNormal.fbx', 'model');

        self::assertTrue($store->delete('Vehicles_CarNormal.fbx'));
        self::assertFalse($store->has('Vehicles_CarNormal.fbx'));
        self::assertFalse($store->delete('Vehicles_CarNormal.fbx'), 'Deleting twice must not claim success.');
    }
}
