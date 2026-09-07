import { apiFetch } from '@/lib/api'

export type TraitDefinition = {
  cost: number
  uiName: string | null
  icon: string | null
  xpBoosts: Record<string, number>
  professionTrait: boolean
  exclusive: string[]
}

export type ProfessionDefinition = {
  cost: number
  uiName: string | null
  icon: string | null
  xpBoosts: Record<string, number>
  grantedTraits: string[]
}

export type CharacterSheet = {
  professions: Record<string, ProfessionDefinition>
  traits: Record<string, TraitDefinition>
  iconsAvailable: boolean
  iconCount: number
}

export function readCharacterSheet(): Promise<CharacterSheet> {
  return apiFetch<CharacterSheet>('/character')
}

/**
 * Which job grants each profession trait.
 *
 * From the installation's `GrantedTraits`. The game's own admin window
 * offers these like any other trait — its only condition is
 * `not hasTrait(...)` — so the panel offers them too, but in a group of
 * their own and named by the job they come from. A player given
 * `burglar` reads as a second profession, which is what an operator
 * granting several jobs in-game is actually doing.
 */
export const TRAIT_PROFESSION: Record<string, string> = {
  axeman: 'lumberjack',
  blacksmith2: 'smither',
  burglar: 'burglar',
  cook2: 'chef',
  desensitized: 'veteran',
  herbalist_prof: 'parkranger',
  inventive_prof: 'repairman',
  mechanics2: 'mechanics',
  nightowl: 'securityguard',
  nutritionist2: 'fitnessinstructor'
}

/**
 * Traits the game drives from the character's weight.
 *
 * Withheld on purpose, unlike the profession traits: the game sets these
 * from the weight itself, so a chip contradicting the weight slider
 * beside it would be a state the game immediately overrules.
 */
export const BUILD_TRAITS = [
  'emaciated',
  'very underweight',
  'underweight',
  'overweight',
  'obese',
] as const

/** Where the game's own artwork is served from. */
export function iconUrl(name: string): string {
  return `/api/character/icons/${encodeURIComponent(name)}.png`
}

/**
 * A trait helps rather than hurts.
 *
 * **Traits only.** The sign means the opposite for a profession, where
 * `cost` is the price rather than a rating: veteran is the dearest job
 * at −8 while unemployed refunds +8. Reading a profession through this
 * would call every good job a drawback.
 */
export function isAdvantage(trait: TraitDefinition): boolean {
  return trait.cost > 0
}

export function setTrait(
  serverId: string,
  username: string,
  trait: string,
  adding: boolean,
): Promise<{ reply: string }> {
  return apiFetch(`/servers/${serverId}/players/${encodeURIComponent(username)}/trait`, {
    method: 'POST',
    body: { trait, adding },
  })
}

/**
 * The traits a player has, split the way the character creator splits
 * them, with the ones a profession granted kept apart.
 *
 * A trait the table does not know about is still listed: a mod defines
 * its own, and dropping it would make the sheet quietly incomplete.
 */
export function splitTraits(
  held: string[],
  table: Record<string, TraitDefinition>,
): {
  good: { id: string; definition: TraitDefinition }[]
  bad: { id: string; definition: TraitDefinition }[]
  fromProfession: { id: string; definition: TraitDefinition }[]
  unknown: string[]
} {
  const good: { id: string; definition: TraitDefinition }[] = []
  const bad: { id: string; definition: TraitDefinition }[] = []
  const fromProfession: { id: string; definition: TraitDefinition }[] = []
  const unknown: string[] = []

  for (const id of held) {
    const definition = table[id]

    if (definition === undefined) {
      unknown.push(id)
      continue
    }

    if (definition.professionTrait) {
      fromProfession.push({ id, definition })
    } else if (isAdvantage(definition)) {
      good.push({ id, definition })
    } else {
      bad.push({ id, definition })
    }
  }

  const byCost = (
    a: { definition: TraitDefinition },
    b: { definition: TraitDefinition },
  ) => Math.abs(b.definition.cost) - Math.abs(a.definition.cost)

  return {
    good: good.sort(byCost),
    bad: bad.sort(byCost),
    fromProfession: fromProfession.sort(byCost),
    unknown: unknown.sort(),
  }
}

/** What can still be chosen: not held, not a profession's, not excluded. */
export function offerableTraits(
  held: string[],
  table: Record<string, TraitDefinition>,
): { id: string; definition: TraitDefinition }[] {
  const owned = new Set(held)
  const blocked = new Set<string>()

  for (const id of held) {
    for (const other of table[id]?.exclusive ?? []) {
      blocked.add(other)
    }
  }

  const build = new Set<string>(BUILD_TRAITS)

  return Object.entries(table)
    .filter(([id]) => !owned.has(id) && !blocked.has(id) && !build.has(id))
    .map(([id, definition]) => ({ id, definition }))
    .sort((a, b) => b.definition.cost - a.definition.cost)
}
