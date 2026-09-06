<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\BridgeSendCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The test command has to send the values the panel sends.
 *
 * `-a on=true` arrived as the **string** "true", and `BridgeCommand`
 * checks `=== true` — so asking to switch the power on switched it off,
 * and the handler looked broken when it was not. A tool that cannot
 * express what the real caller sends proves the wrong thing, which is
 * worse than having no tool.
 */
final class BridgeSendArgumentsTest extends TestCase
{
    /**
     * @param list<string>          $pairs
     * @param array<string, scalar> $expected
     */
    #[DataProvider('pairs')]
    public function testParsesTheTypesAJsonBodyWouldCarry(array $pairs, array $expected): void
    {
        self::assertSame($expected, self::parse($pairs));
    }

    /** @return iterable<string, array{list<string>, array<string, scalar>}> */
    public static function pairs(): iterable
    {
        yield 'a boolean stays a boolean' => [['on=true'], ['on' => true]];
        yield 'and so does false' => [['on=false'], ['on' => false]];
        yield 'whatever the case' => [['on=TRUE'], ['on' => true]];

        yield 'an integer is a number' => [['duration=4'], ['duration' => 4]];
        yield 'so is a float' => [['strength=0.5'], ['strength' => 0.5]];
        yield 'and a negative one' => [['value=-5'], ['value' => -5]];

        yield 'a name is a string' => [['utility=power'], ['utility' => 'power']];
        yield 'an empty value is an empty string' => [['note='], ['note' => '']];

        // A value carrying an equals sign keeps it: a note or a reason
        // may well contain one.
        yield 'only the first equals splits' => [['reason=a=b'], ['reason' => 'a=b']];

        yield 'several arguments' => [
            ['utility=water', 'on=false'],
            ['utility' => 'water', 'on' => false],
        ];
    }

    /**
     * @param list<string> $pairs
     *
     * @return array<string, scalar>
     */
    private static function parse(array $pairs): array
    {
        $method = new \ReflectionMethod(BridgeSendCommand::class, 'parse');

        /** @var array<string, scalar> $parsed */
        $parsed = $method->invoke(null, $pairs);

        return $parsed;
    }
}
