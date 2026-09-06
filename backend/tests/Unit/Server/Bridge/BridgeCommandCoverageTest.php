<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Bridge;

use App\Server\Bridge\BridgeCommand;
use PHPUnit\Framework\TestCase;

/**
 * Every command the panel can send must have a handler in the Lua.
 *
 * The two sides are separate files and the mod is uploaded by hand, so a
 * command added to the enum and not to the bridge fails only on a live
 * server, as "unknown action" long after the change was made.
 */
final class BridgeCommandCoverageTest extends TestCase
{
    private const LUA = __DIR__.'/../../../../resources/bridge/ZomboidControlBridge.lua';

    public function testEveryCommandHasALuaHandler(): void
    {
        $lua = self::lua();

        foreach (BridgeCommand::cases() as $command) {
            self::assertStringContainsString(
                sprintf('handlers.%s = function', $command->value),
                $lua,
                sprintf('the bridge has no handler for "%s"', $command->value),
            );
        }
    }

    /** And nothing in the Lua the panel has no way to reach. */
    public function testEveryLuaHandlerHasACommand(): void
    {
        $declared = array_map(
            static fn (BridgeCommand $command): string => $command->value,
            BridgeCommand::cases(),
        );

        preg_match_all('/handlers\.(\w+) = function/', self::lua(), $found);

        foreach ($found[1] as $handler) {
            self::assertContains(
                $handler,
                $declared,
                sprintf('the bridge handles "%s", which no command sends', $handler),
            );
        }
    }

    /**
     * The version has to move when a handler is added, or an operator
     * running the older mod is told they are up to date while the panel
     * sends it commands it cannot answer.
     */
    public function testTheBridgeDeclaresAVersion(): void
    {
        self::assertSame(
            1,
            preg_match('/local BRIDGE_VERSION = "(\d+\.\d+\.\d+)"/', self::lua(), $found),
        );

        self::assertNotSame('0.0.0', $found[1]);
    }

    private static function lua(): string
    {
        $contents = file_get_contents(self::LUA);

        self::assertIsString($contents, 'the bridge is missing');

        return $contents;
    }
}
