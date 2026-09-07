/**
 * How the game groups its skills, read from `PerkFactory`'s bytecode
 * rather than guessed.
 *
 * Three of these cannot be inferred from the id: `Woodwork` is displayed
 * as "Carpentry", `PlantScavenging` as "Foraging", and `Doctor` hangs
 * under Survivalist rather than Crafting. Categories are the perks whose
 * own parent is `None` — the game's character sheet skips them as
 * headings, and so does this.
 */
export const SKILL_CATEGORIES = [
  { id: 'Combat', skills: ['Axe', 'Blunt', 'SmallBlunt', 'LongBlade', 'SmallBlade', 'Spear', 'Maintenance'] },
  { id: 'Firearm', skills: ['Aiming', 'Reloading'] },
  {
    id: 'Crafting',
    skills: [
      'Woodwork',
      'Carving',
      'Cooking',
      'Electricity',
      'Glassmaking',
      'FlintKnapping',
      'Masonry',
      'Blacksmith',
      'Mechanics',
      'Pottery',
      'Tailoring',
      'MetalWelding',
    ],
  },
  { id: 'Survivalist', skills: ['Doctor', 'Fishing', 'PlantScavenging', 'Tracking', 'Trapping'] },
  { id: 'PhysicalCategory', skills: ['Fitness', 'Strength', 'Lightfoot', 'Nimble', 'Sprinting', 'Sneak'] },
  { id: 'FarmingCategory', skills: ['Farming', 'Husbandry', 'Butchering'] },
] as const

/** The display names that differ from the id, from the same source. */
export const SKILL_LABELS: Record<string, string> = {
  Woodwork: 'Carpentry',
  PlantScavenging: 'Foraging',
  Lightfoot: 'Lightfooted',
  Sneak: 'Sneaking',
}

export const MAX_SKILL_LEVEL = 10

export function skillLabel(id: string): string {
  return SKILL_LABELS[id] ?? id
}

/**
 * Every known skill with this player's level, grouped as the game groups
 * them — including the ones at zero, because "they have no mechanics at
 * all" is an answer a dossier has to be able to give.
 */
export function groupSkills(
  levels: Record<string, number> | null,
): { id: string; skills: { id: string; label: string; level: number }[]; trained: number }[] {
  const known = levels ?? {}

  return SKILL_CATEGORIES.map((category) => {
    const skills = category.skills.map((id) => ({
      id,
      label: skillLabel(id),
      level: known[id] ?? 0,
    }))

    return {
      id: category.id,
      skills,
      trained: skills.filter((skill) => skill.level > 0).length,
    }
  })
}

/** Anything the server reported that this table does not know about. */
export function unknownSkills(levels: Record<string, number> | null): string[] {
  const listed = new Set(SKILL_CATEGORIES.flatMap((category) => category.skills as readonly string[]))

  return Object.keys(levels ?? {})
    .filter((id) => !listed.has(id))
    .sort()
}
