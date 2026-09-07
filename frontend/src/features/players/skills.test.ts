import { describe, expect, it } from 'vitest'

import de from '@/i18n/locales/de.json'
import { groupSkills, SKILL_CATEGORIES, SKILL_LABELS, skillLabel, unknownSkills } from './skills'

/**
 * The skill table is the game's own, taken from `PerkFactory`'s bytecode.
 * Three of its entries cannot be derived from the id, which is exactly
 * why they are worth pinning.
 */
describe('the skill table', () => {
  it('keeps the three display names that differ from the id', () => {
    expect(skillLabel('Woodwork')).toBe('Carpentry')
    expect(skillLabel('PlantScavenging')).toBe('Foraging')
    expect(skillLabel('Lightfoot')).toBe('Lightfooted')
  })

  it('leaves an id alone when the game does', () => {
    expect(skillLabel('Cooking')).toBe('Cooking')
  })

  /** Doctor hangs under Survivalist, not under Crafting. */
  it('puts first aid where the game puts it', () => {
    const survivalist = SKILL_CATEGORIES.find((group) => group.id === 'Survivalist')

    expect(survivalist?.skills).toContain('Doctor')

    const crafting = SKILL_CATEGORIES.find((group) => group.id === 'Crafting')

    expect(crafting?.skills).not.toContain('Doctor')
  })

  /** A category is a heading; listing one as a skill would show a
      progress bar for something that has no level. */
  it('lists no category as a skill', () => {
    const everySkill = SKILL_CATEGORIES.flatMap((group) => group.skills as readonly string[])

    for (const category of ['Combat', 'Firearm', 'Crafting', 'Survivalist', 'PhysicalCategory', 'FarmingCategory', 'Agility', 'Passiv', 'None', 'MAX']) {
      expect(everySkill).not.toContain(category)
    }
  })

  it('has no skill in two categories', () => {
    const everySkill = SKILL_CATEGORIES.flatMap((group) => group.skills as readonly string[])

    expect(new Set(everySkill).size).toBe(everySkill.length)
  })

  /** The pips are a fixed ten, so a level over that would overflow. */
  it('groups every skill with a level, including zero', () => {
    const grouped = groupSkills({ Axe: 4, Cooking: 10 })
    const combat = grouped.find((group) => group.id === 'Combat')

    expect(combat?.skills.find((skill) => skill.id === 'Axe')?.level).toBe(4)
    expect(combat?.skills.find((skill) => skill.id === 'Blunt')?.level).toBe(0)
    expect(combat?.trained).toBe(1)
  })

  it('reports a mod skill rather than dropping it', () => {
    expect(unknownSkills({ Axe: 3, SomeModSkill: 5 })).toEqual(['SomeModSkill'])
    expect(unknownSkills(null)).toEqual([])
  })

  /**
   * A skill with no German name shows its English id, which is a silent
   * gap rather than a visible one — so the label table is checked.
   */
  it('has a German name for every skill the table lists', () => {
    const labels = de.players.skillName as Record<string, string>

    for (const group of SKILL_CATEGORIES) {
      for (const id of group.skills) {
        expect(labels[skillLabel(id)], `${id} has no German name`).toBeTruthy()
      }
    }
  })

  it('has a German name for every category', () => {
    const groups = de.players.skillGroup as Record<string, string>

    for (const group of SKILL_CATEGORIES) {
      expect(groups[group.id], `${group.id} has no German name`).toBeTruthy()
    }
  })

  /** Every renamed id must be one the table actually uses. */
  it('renames nothing it does not list', () => {
    const everySkill = SKILL_CATEGORIES.flatMap((group) => group.skills as readonly string[])

    for (const id of Object.keys(SKILL_LABELS)) {
      expect(everySkill).toContain(id)
    }
  })
})
