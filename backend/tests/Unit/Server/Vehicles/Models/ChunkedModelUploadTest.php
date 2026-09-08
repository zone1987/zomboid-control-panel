<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Vehicles\Models;

use App\Server\Items\Icons\ChunkedUpload;
use App\Server\Items\Icons\UploadRefused;
use PHPUnit\Framework\TestCase;

/**
 * A vehicle model that arrives in pieces has to come back whole.
 *
 * Splitting is the easy half; the question worth testing is whether
 * the pieces are reassembled in order, verified against the length the
 * browser promised, and discarded when they are not -- half a model
 * written to the store would draw a broken vehicle on the map with
 * nothing said.
 */
final class ChunkedModelUploadTest extends TestCase
{
    private string $directory;
    private ChunkedUpload $upload;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/model-upload-'.bin2hex(random_bytes(6));
        $this->upload = new ChunkedUpload($this->directory);
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

    /** The whole point: what went out in pieces comes back identical. */
    public function testReassemblesAModelFromItsPieces(): void
    {
        $model = random_bytes(20_000);
        $chunk = 8_192;
        $offset = 0;

        foreach (str_split($model, $chunk) as $piece) {
            $this->upload->appendAny('Vehicles_Van.fbx', $offset, $piece, false);
            $offset += \strlen($piece);
        }

        self::assertSame(
            $model,
            $this->upload->takeAny('Vehicles_Van.fbx', \strlen($model)),
            'the reassembled model differs from what was sent',
        );
    }

    /** A model is not a texture pack, so the header check must not apply. */
    public function testDoesNotDemandATexturePackHeader(): void
    {
        $this->upload->appendAny('Vehicles_Van.fbx', 0, random_bytes(64), false);

        self::assertSame(64, \strlen($this->upload->takeAny('Vehicles_Van.fbx', 64)));
    }

    /** Nothing is left behind to be picked up by the next upload. */
    public function testDeletesThePartialFileOnceTaken(): void
    {
        $this->upload->appendAny('Vehicles_Van.fbx', 0, random_bytes(128), false);
        $this->upload->takeAny('Vehicles_Van.fbx', 128);

        self::assertSame([], glob($this->directory.'/*') ?: []);
    }

    /**
     * A piece that arrives out of order is refused rather than written
     * at the wrong place -- silent corruption is the worse failure.
     */
    public function testRefusesAPieceThatDoesNotFollowTheLast(): void
    {
        $this->upload->appendAny('Vehicles_Van.fbx', 0, random_bytes(100), false);

        $this->expectException(UploadRefused::class);

        $this->upload->appendAny('Vehicles_Van.fbx', 500, random_bytes(100), false);
    }

    /** A short arrival is discarded, not stored as a truncated model. */
    public function testRefusesAndDiscardsAModelThatArrivedShort(): void
    {
        $this->upload->appendAny('Vehicles_Van.fbx', 0, random_bytes(100), false);

        try {
            $this->upload->takeAny('Vehicles_Van.fbx', 200);
            self::fail('a short upload should have been refused');
        } catch (UploadRefused $refused) {
            self::assertSame('icons.sizeMismatch', $refused->messageKey());
        }

        self::assertSame([], glob($this->directory.'/*') ?: [], 'the partial file must not survive');
    }

    /** Starting again at zero replaces the attempt rather than appending. */
    public function testARestartedUploadDoesNotAppendToTheOldOne(): void
    {
        $this->upload->appendAny('Vehicles_Van.fbx', 0, str_repeat('a', 100), false);

        $second = str_repeat('b', 50);
        $this->upload->appendAny('Vehicles_Van.fbx', 0, $second, false);

        self::assertSame($second, $this->upload->takeAny('Vehicles_Van.fbx', 50));
    }

    /** The extensions a model may carry, and nothing else. */
    public function testAcceptsOnlyModelFileNames(): void
    {
        foreach (['Vehicles_Van.fbx', 'van_texture.png', 'wheel.txt'] as $name) {
            self::assertSame(
                $name,
                ChunkedUpload::safeFileName($name, 'fbx', 'png', 'txt'),
                $name.' is a model file and should be accepted',
            );
        }

        foreach (['van.exe', '../etc/passwd', 'van.fbx.php', ''] as $name) {
            self::assertNull(
                ChunkedUpload::safeFileName($name, 'fbx', 'png', 'txt'),
                $name.' must not be accepted',
            );
        }
    }

    /** Two uploads at once must not write into each other's file. */
    public function testKeepsTwoModelsApart(): void
    {
        $this->upload->appendAny('one.fbx', 0, str_repeat('1', 30), false);
        $this->upload->appendAny('two.fbx', 0, str_repeat('2', 40), false);

        self::assertSame(str_repeat('1', 30), $this->upload->takeAny('one.fbx', 30));
        self::assertSame(str_repeat('2', 40), $this->upload->takeAny('two.fbx', 40));
    }
}
