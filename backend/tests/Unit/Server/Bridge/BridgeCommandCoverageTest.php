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


    /**
     * Nothing touches `handlers` before it exists.
     *
     * A handler that ended up above `local handlers = {}` threw
     * "attempted index of non-table" the moment the game loaded the
     * file, so the whole bridge never started: no files written, every
     * command timing out. **`luac -p` does not catch this** — it checks
     * syntax, not whether a name is in scope — which is why it needs a
     * test of its own.
     */
    public function testNoHandlerIsDefinedBeforeTheTableExists(): void
    {
        $lua = self::lua();
        $lines = explode("\n", $lua);

        $declaredAt = null;

        foreach ($lines as $number => $line) {
            if (preg_match('/^local handlers = \{\}/', $line) === 1) {
                $declaredAt = $number;

                break;
            }
        }

        self::assertNotNull($declaredAt, 'the bridge no longer declares a handlers table');

        foreach ($lines as $number => $line) {
            if (preg_match('/^handlers\.(\w+) = function/', $line, $found) === 1) {
                self::assertGreaterThan(
                    $declaredAt,
                    $number,
                    sprintf(
                        'handlers.%s is defined on line %d, before the table exists on line %d',
                        $found[1],
                        $number + 1,
                        $declaredAt + 1,
                    ),
                );
            }
        }
    }

    /** And none is defined twice, which would silently shadow the first. */
    public function testNoHandlerIsDefinedTwice(): void
    {
        preg_match_all('/^handlers\.(\w+) = function/m', self::lua(), $found);

        $counts = array_count_values($found[1]);

        foreach ($counts as $name => $count) {
            self::assertSame(1, $count, sprintf('handlers.%s is defined %d times', $name, $count));
        }
    }

    private static function lua(): string
    {
        $contents = file_get_contents(self::LUA);

        self::assertIsString($contents, 'the bridge is missing');

        return $contents;
    }
}
