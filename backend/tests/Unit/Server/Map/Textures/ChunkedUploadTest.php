<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Map\Textures;

use App\Server\Map\Textures\ChunkedUpload;
use App\Server\Map\Textures\TexturePackStore;
use App\Server\Map\Textures\UploadRefused;
use PHPUnit\Framework\TestCase;

/**
 * A 306 MB pack arriving in pieces through a container that accepts
 * 16 MB. What matters is that a pack which arrives wrong is refused
 * rather than stored: a short one parses far enough to look valid and
 * then renders holes into the map.
 */
final class ChunkedUploadTest extends TestCase
{
    private string $root;
    private ChunkedUpload $upload;
    private TexturePackStore $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/pack-test-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/packs', 0o775, true);
        mkdir($this->root.'/tmp', 0o775, true);

        $this->store = new TexturePackStore($this->root.'/packs');
        $this->upload = new ChunkedUpload($this->store, $this->root.'/tmp');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->root.'/packs');
        @rmdir($this->root.'/tmp');
        @rmdir($this->root);
    }

    public function testAssemblesAPackFromItsPieces(): void
    {
        $body = TexturePackStore::MAGIC.str_repeat('x', 300);

        $this->upload->append('Tiles2x.pack', 0, substr($body, 0, 100));
        $this->upload->append('Tiles2x.pack', 100, substr($body, 100));
        $this->upload->finish('Tiles2x.pack', \strlen($body));

        self::assertSame($body, file_get_contents($this->store->pathFor('Tiles2x.pack')));
    }

    /**
     * The game ships both pack formats and JumboTrees2x.pack is the
     * older one: no PZPK marker, straight into a page count. Demanding
     * the marker refused a file the renderer reads perfectly well.
     *
     * The opening bytes here are the real ones, read off a Build 42
     * installation: 12 pages, a 13-character first page name.
     */
    public function testAcceptsAVersionZeroPackWithNoMarker(): void
    {
        $body = pack('VV', 12, 13).'JumboTrees2x0'.str_repeat('x', 200);

        $this->upload->append('JumboTrees2x.pack', 0, $body);
        $this->upload->finish('JumboTrees2x.pack', \strlen($body));

        self::assertArrayHasKey('JumboTrees2x.pack', $this->store->present());
    }

    public function testRefusesAFileThatIsNotATexturePack(): void
    {
        $this->expectException(UploadRefused::class);

        $this->upload->append('Tiles2x.pack', 0, "PK\x03\x04".str_repeat("\xff", 50));
    }

    public function testRefusesAPieceThatWouldLandInTheWrongPlace(): void
    {
        $this->upload->append('Tiles2x.pack', 0, TexturePackStore::MAGIC.'aaaa');

        $this->expectException(UploadRefused::class);

        // 8 bytes have arrived, so a piece claiming to start at 99 means
        // one went missing on the way.
        $this->upload->append('Tiles2x.pack', 99, 'bbbb');
    }

    public function testRefusesAPackThatArrivedShort(): void
    {
        $this->upload->append('Tiles2x.pack', 0, TexturePackStore::MAGIC.'aaaa');

        try {
            $this->upload->finish('Tiles2x.pack', 5_000);
            self::fail('A short pack was accepted.');
        } catch (UploadRefused $refused) {
            self::assertSame('map.sizeMismatch', $refused->messageKey());
        }

        self::assertSame([], $this->store->present(), 'The short pack was stored anyway.');
    }

    public function testReportsHowMuchHasArrivedSoAnUploadCanResume(): void
    {
        $this->upload->append('Tiles2x.pack', 0, TexturePackStore::MAGIC.str_repeat('x', 96));

        self::assertSame(100, $this->upload->received('Tiles2x.pack'));
    }

    public function testStartingOverDiscardsWhatCameBefore(): void
    {
        $this->upload->append('Tiles2x.pack', 0, TexturePackStore::MAGIC.str_repeat('x', 96));
        $this->upload->append('Tiles2x.pack', 0, TexturePackStore::MAGIC.'yyyy');

        self::assertSame(8, $this->upload->received('Tiles2x.pack'));
    }

    /** A name off the list has no business being written at all. */
    public function testRefusesAPackTheRendererDoesNotRead(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->upload->append('../../etc/passwd', 0, TexturePackStore::MAGIC);
    }

    public function testNamesWhatIsStillMissing(): void
    {
        self::assertSame(TexturePackStore::REQUIRED, $this->store->missing());
        self::assertFalse($this->store->isComplete());
    }
}
