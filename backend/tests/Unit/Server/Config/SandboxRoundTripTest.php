<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Config;

use App\Server\Config\LuaTableReader;
use App\Server\Config\LuaTableWriter;
use PHPUnit\Framework\TestCase;

/**
 * The reader and writer against the game's own default sandbox file.
 *
 * A synthetic fixture cannot prove this: the traps are all *in* the real
 * template — a string holding eight commas, five nested tables holding
 * 86 of the values, and `Farming` meaning two different things.
 */
final class SandboxRoundTripTest extends TestCase
{
    /**
     * The game's own default template, taken from the dump rather than
     * copied into the repository: it is The Indie Stone's file, and
     * CLAUDE.md 10b keeps their content out of git. The dump lives in
     * `backend/var/`, which is ignored.
     */
    private const DUMP = __DIR__.'/../../../../var/game-config.json';

    public function testReadsEveryValueInTheGamesOwnTemplate(): void
    {
        $values = (new LuaTableReader())->read(self::source());

        self::assertCount(269, $values, 'the template holds 269 values; a different count means the parser drifted');
        self::assertCount(
            86,
            array_filter(array_keys($values), static fn (string $key): bool => str_contains($key, '.')),
            '86 of them live in the five nested tables',
        );
    }

    public function testTheCommaBearingStringSurvives(): void
    {
        $values = (new LuaTableReader())->read(self::source());

        self::assertSame(
            'Base.Hat, Base.Glasses, Base.Maggots, Base.Slug, Base.Slug2, Base.Snail, Base.Worm, Base.Dung_Mouse, Base.Dung_Rat',
            $values['WorldItemRemovalList'],
        );
    }

    public function testFarmingIsTwoSeparateOptions(): void
    {
        $values = (new LuaTableReader())->read(self::source());

        self::assertSame(3, $values['Farming'], 'the skill growth rate');
        self::assertSame(1.0, $values['MultiplierConfig.Farming'], 'the XP multiplier');
    }

    /**
     * Writing every value back, then reading it: the file must hold
     * exactly what was asked for and nothing else may move.
     */
    public function testWritingEveryValueChangesNothingElse(): void
    {
        $reader = new LuaTableReader();
        $source = self::source();
        $before = $reader->read($source);

        $changes = [];

        foreach ($before as $key => $value) {
            $changes[$key] = match (true) {
                is_bool($value) => !$value,
                is_int($value) => $value + 1,
                is_float($value) => $value + 0.5,
                default => $value.'!',
            };
        }

        $after = $reader->read((new LuaTableWriter())->apply($source, $changes));

        self::assertSame(array_keys($before), array_keys($after), 'no key gained or lost');
        self::assertSame($changes, $after, 'every value came back exactly as written');
    }

    /**
     * The result has to be loadable Lua, or the server will not boot.
     * `luac -p` parses without executing, which is the same proof the
     * reference panel got from embedding a whole Lua VM in its tests.
     */
    public function testTheWrittenFileIsValidLua(): void
    {
        $luac = trim((string) shell_exec('command -v luac 2>/dev/null'));

        if ($luac === '') {
            self::markTestSkipped('luac is not installed in this environment');
        }

        $written = (new LuaTableWriter())->apply(self::source(), [
            'Zombies' => 1,
            'WorldItemRemovalList' => 'Base.Hat, Base."quoted", Base.Worm',
            'ZombieLore.Speed' => 3,
            'MultiplierConfig.Farming' => 2.5,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'sandbox').'.lua';
        file_put_contents($path, $written);

        $output = (string) shell_exec(sprintf('%s -p %s 2>&1', escapeshellarg($luac), escapeshellarg($path)));

        unlink($path);

        self::assertSame('', trim($output), 'luac rejected the written file: '.$output);
    }

    private static function source(): string
    {
        $raw = @file_get_contents(self::DUMP);

        if (!is_string($raw)) {
            self::markTestSkipped(
                'no game dump; run `bash backend/tools/dump-game-config.sh` on the host',
            );
        }

        $decoded = json_decode($raw, true);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('files', $decoded);
        self::assertIsString($decoded['files']['defaults'] ?? null, 'the dump holds no default template');

        return $decoded['files']['defaults'];
    }
}
