<?php

declare(strict_types=1);

namespace App\Tests\Unit\Server\Bridge;

use App\Server\Bridge\BridgeCommand;
use PHPUnit\Framework\TestCase;

/**
 * The skill list exists on both sides and must not drift.
 *
 * The panel groups by six categories in `skills.ts`; the bridge command
 * validates against `BridgeCommand::SKILLS`. A skill in one and not the
 * other is either an unusable control or a command the bridge refuses,
 * so the interface's own table is read here and compared.
 */
final class BridgeSkillListTest extends TestCase
{
    private const SKILLS_TS = __DIR__.'/../../../../../frontend/src/features/players/skills.ts';

    public function testHoldsEverySkillTheInterfaceGroups(): void
    {
        self::assertSame(
            self::fromInterface(),
            BridgeCommand::SKILLS,
            'the bridge and the interface disagree on the skill list',
        );
    }

    /**
     * A category is a heading with no level of its own; `setSkillLevel`
     * on one would be a command the game cannot answer.
     */
    public function testListsNoCategoryAsASkill(): void
    {
        foreach ([
            'Combat',
            'Firearm',
            'Crafting',
            'Survivalist',
            'PhysicalCategory',
            'FarmingCategory',
            'Agility',
            'Passiv',
            'None',
            'MAX',
        ] as $category) {
            self::assertNotContains(
                $category,
                BridgeCommand::SKILLS,
                sprintf('%s is a category, not a skill', $category),
            );
        }
    }

    public function testTheCeilingIsTheGamesOwn(): void
    {
        self::assertSame(10, BridgeCommand::MAX_SKILL_LEVEL);
    }

    /** @return list<string> */
    private static function fromInterface(): array
    {
        $source = file_get_contents(self::SKILLS_TS);

        self::assertIsString($source, 'skills.ts is missing');

        $start = strpos($source, 'SKILL_CATEGORIES = [');
        $end = strpos($source, '] as const');

        self::assertIsInt($start);
        self::assertIsInt($end);

        $block = substr($source, $start, $end - $start);

        // Each category is `{ id: 'X', skills: [...] }`; only the arrays
        // hold skills, so the ids never enter the comparison.
        preg_match_all("/skills: \[([^\]]*)\]/s", $block, $groups);

        $skills = [];

        foreach ($groups[1] as $group) {
            preg_match_all("/'([A-Za-z]+)'/", $group, $found);
            $skills = [...$skills, ...$found[1]];
        }

        self::assertGreaterThan(30, \count($skills), 'skills.ts parsed to almost nothing');

        return $skills;
    }
}
