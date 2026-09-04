<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Logs;

use App\Server\Logs\LogFileFinder;
use PHPUnit\Framework\TestCase;

/**
 * Names taken from a live Build 42 server, which stamps the start time
 * into every log and begins a fresh set on each restart.
 */
final class LogFileFinderTest extends TestCase
{
    public function testRecognisesEachKindOfLog(): void
    {
        self::assertSame('chat', LogFileFinder::kindOf('2026-09-04_18-27_chat.txt'));
        self::assertSame('server', LogFileFinder::kindOf('2026-09-04_18-27_DebugLog-server.txt'));
        self::assertSame('connections', LogFileFinder::kindOf('2026-09-04_18-27_connections.txt'));
        self::assertSame('pvp', LogFileFinder::kindOf('2026-09-04_18-27_pvp.txt'));
        self::assertSame('perks', LogFileFinder::kindOf('2026-09-04_18-27_PerkLog.txt'));
    }

    public function testIgnoresAFileItDoesNotRecognise(): void
    {
        self::assertNull(LogFileFinder::kindOf('2026-09-04_18-27_something-else.txt'));
        self::assertNull(LogFileFinder::kindOf('logs_2026-09-04'));
    }

    /**
     * "DebugLog-server.txt" and "server.txt" would both end in the same
     * suffix without the separator, so the underscore is part of the match.
     */
    public function testDoesNotMatchASuffixThatIsOnlyPartOfAName(): void
    {
        self::assertNull(LogFileFinder::kindOf('chat.txt'));
        self::assertNull(LogFileFinder::kindOf('mychat.txt'));
    }
}
