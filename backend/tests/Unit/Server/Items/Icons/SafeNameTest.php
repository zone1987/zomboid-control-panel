<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Items\Icons;

use App\Server\Items\Icons\ChunkedUpload;
use PHPUnit\Framework\TestCase;

/**
 * Item packs cannot be checked against a list: a mod brings its own,
 * named by its author, and an operator running twenty mods would
 * otherwise see their artwork silently refused.
 */
final class SafeNameTest extends TestCase
{
    public function testAcceptsAVanillaPack(): void
    {
        self::assertSame('UI2.pack', ChunkedUpload::safeName('UI2.pack'));
    }

    public function testAcceptsAModPackNobodyCouldHaveListed(): void
    {
        self::assertSame(
            'Brita_Weapons_UI.pack',
            ChunkedUpload::safeName('Brita_Weapons_UI.pack'),
        );
    }

    public function testStripsADirectoryFromTheName(): void
    {
        self::assertSame('UI2.pack', ChunkedUpload::safeName('../../etc/UI2.pack'));
    }

    public function testRefusesAnythingThatIsNotAPack(): void
    {
        self::assertNull(ChunkedUpload::safeName('passwd'));
        self::assertNull(ChunkedUpload::safeName('evil.php'));
        self::assertNull(ChunkedUpload::safeName(''));
    }

    public function testRefusesANameWithSeparatorsOrSpaces(): void
    {
        self::assertNull(ChunkedUpload::safeName('a b.pack'));
        self::assertNull(ChunkedUpload::safeName("UI\0.pack"));
    }
}
