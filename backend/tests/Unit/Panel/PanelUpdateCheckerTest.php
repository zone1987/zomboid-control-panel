<?php

declare(strict_types=1);

namespace App\Tests\Unit\Panel;

use App\Panel\PanelUpdateChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PanelUpdateCheckerTest extends TestCase
{
    /** @return list<array{string, string, bool}> */
    public static function comparisons(): array
    {
        return [
            ['0.2.0', '0.1.0', true],
            ['1.0.0', '0.9.9', true],
            ['0.1.1', '0.1.0', true],
            ['0.1.0', '0.1.0', false],
            ['0.1.0', '0.2.0', false],
            // A shorter version is not automatically older: 1.2 equals 1.2.0.
            ['1.2', '1.2.0', false],
            ['1.2.1', '1.2', true],
            // Ten is larger than nine, which string comparison gets wrong.
            ['0.10.0', '0.9.0', true],
            ['0.9.0', '0.10.0', false],
        ];
    }

    #[DataProvider('comparisons')]
    public function testComparesDottedVersionsNumerically(
        string $candidate,
        string $against,
        bool $expected,
    ): void {
        self::assertSame($expected, PanelUpdateChecker::isNewer($candidate, $against));
    }

    /** An unreadable version must never be announced as an update. */
    public function testTreatsAnUnparseableVersionAsNotNewer(): void
    {
        self::assertFalse(PanelUpdateChecker::isNewer('nightly', '0.1.0'));
        self::assertFalse(PanelUpdateChecker::isNewer('0.2.0', 'unknown'));
    }
}
