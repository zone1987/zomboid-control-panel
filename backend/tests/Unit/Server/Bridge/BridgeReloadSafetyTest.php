<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Bridge;

use PHPUnit\Framework\TestCase;

/**
 * What a reloadlua of the bridge depends on, asserted against the Lua.
 *
 * The bridge is uploaded by hand and re-executed on a running server, so
 * each of these fails only in production -- silently, as work done twice
 * or a command queue replayed from the start.
 */
final class BridgeReloadSafetyTest extends TestCase
{
    private const LUA = __DIR__.'/../../../../resources/bridge/ZomboidControlBridge.lua';

    /**
     * RunLua(path, true) sets LuaCompiler.rewriteEvents, and while it is
     * set Event$Remove.call returns before touching the callback list.
     * The remove is therefore a silent no-op and the Add that follows
     * appends a second callback, which LuaEventManager.reroute can no
     * longer replace -- producing the doubled onTick the call was meant
     * to prevent.
     */
    public function testNoEventIsUnregistered(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/Events\.[A-Za-z]+\.Remove/',
            self::code(),
            'Events.X.Remove is a no-op during a reload and breaks reroute; do not call it',
        );
    }

    /**
     * reroute matches a callback by prototype filename AND name, so an
     * inline anonymous handler cannot be replaced on a reload.
     */
    public function testEveryEventHandlerIsANamedFunction(): void
    {
        preg_match_all('/Events\.([A-Za-z]+)\.Add\(([^)]*)\)/', self::code(), $matches, PREG_SET_ORDER);

        self::assertNotEmpty($matches, 'the bridge registers no events at all');

        foreach ($matches as $match) {
            self::assertMatchesRegularExpression(
                '/^[A-Za-z_][A-Za-z0-9_.]*$/',
                trim($match[2]),
                sprintf('Events.%s.Add must be given a named function, not an inline one', $match[1]),
            );
        }
    }

    /**
     * OnServerStarted does not fire on a reload, so the once-per-start
     * work is a named function the module body can call directly.
     * Without it the cursor is never read and every command the panel
     * ever sent replays from sequence 1.
     */
    public function testTheStartBlockIsCallableOnAReload(): void
    {
        $lua = self::lua();

        self::assertStringContainsString(
            'local function onServerStarted()',
            $lua,
            'the once-per-start work must be a named function, callable after a reload',
        );

        self::assertMatchesRegularExpression(
            '/if reloaded then\s+(?:--[^\n]*\n\s*)*onServerStarted\(\)/',
            $lua,
            'a reload must call onServerStarted itself, since the event will not fire',
        );
    }

    /** The guard only works if the previous run left its mark globally. */
    public function testTheReloadIsDetectedThroughAGlobal(): void
    {
        $lua = self::lua();

        self::assertStringContainsString(
            'ZomboidControlBridge = ZomboidControlBridge or {}',
            $lua,
            'a local cannot survive re-execution, so the marker is a global table',
        );

        self::assertStringContainsString(
            'local reloaded = ZomboidControlBridge.registered == true',
            $lua,
            'the reload is detected by the marker the previous run set',
        );

        self::assertStringContainsString(
            'ZomboidControlBridge.registered = true',
            $lua,
            'the marker must be set, or every load looks like a first load',
        );
    }

    /**
     * readCursor is what stops the replay, so it stays inside the block a
     * reload calls rather than beside the event registration.
     */
    public function testTheCursorIsReadInTheStartBlock(): void
    {
        $start = self::startBlock();

        self::assertStringContainsString('readCursor()', $start, 'the start block must read the cursor');
        self::assertStringContainsString('attempt("items", writeItems)', $start);
        self::assertStringContainsString('attempt("vehicleCatalogue", writeVehicleCatalogue)', $start);
        self::assertStringContainsString('attempt("probe", writeProbe)', $start);
    }

    private static function startBlock(): string
    {
        self::assertSame(
            1,
            preg_match('/local function onServerStarted\(\)(.*?)\nend\n/s', self::lua(), $matches),
            'onServerStarted is not declared in the expected shape',
        );

        return $matches[1];
    }

    /** The Lua with its comments stripped: the rules bind code, not prose. */
    private static function code(): string
    {
        return (string) preg_replace(
            ['/--\[\[.*?\]\]/s', '/--[^\n]*/'],
            '',
            self::lua(),
        );
    }

    private static function lua(): string
    {
        $lua = file_get_contents(self::LUA);

        self::assertIsString($lua, 'the bridge source is missing');

        return $lua;
    }
}
