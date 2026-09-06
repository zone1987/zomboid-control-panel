<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Items\Icons;

use App\Server\Items\Icons\ChunkedUpload;
use App\Server\Items\Icons\UploadRefused;
use PHPUnit\Framework\TestCase;

final class ChunkedUploadTest extends TestCase
{
    private string $directory;
    private ChunkedUpload $upload;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/icon-upload-'.bin2hex(random_bytes(6));
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

    public function testAssemblesIconPackAndDeletesTheTemporaryFile(): void
    {
        $body = 'PZPK'.str_repeat('x', 200);
        $this->upload->appendAny('UI2.pack', 0, substr($body, 0, 100));
        $this->upload->appendAny('UI2.pack', 100, substr($body, 100));
        self::assertSame($body, $this->upload->takeAny('UI2.pack', strlen($body)));
        self::assertFileDoesNotExist($this->directory.'/UI2.pack.part');
    }

    public function testAcceptsLegacyPackWithoutMagic(): void
    {
        $body = pack('VV', 12, 13).str_repeat('x', 100);
        $this->upload->appendAny('Legacy.pack', 0, $body);
        self::assertSame($body, $this->upload->takeAny('Legacy.pack', strlen($body)));
    }

    public function testRejectsNonPackContent(): void
    {
        $this->expectException(UploadRefused::class);
        $this->upload->appendAny('UI2.pack', 0, str_repeat("\xff", 50));
    }

    public function testRejectsOutOfOrderChunk(): void
    {
        $this->upload->appendAny('UI2.pack', 0, 'PZPKxxxx');
        $this->expectException(UploadRefused::class);
        $this->upload->appendAny('UI2.pack', 99, 'xxxx');
    }

    public function testRejectsAndDiscardsIncompleteUpload(): void
    {
        $this->upload->appendAny('UI2.pack', 0, 'PZPKxxxx');
        try {
            $this->upload->takeAny('UI2.pack', 100);
            self::fail('Incomplete uploads must be refused.');
        } catch (UploadRefused $exception) {
            self::assertSame('icons.sizeMismatch', $exception->messageKey());
            self::assertFileDoesNotExist($this->directory.'/UI2.pack.part');
        }
    }
}
