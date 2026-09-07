import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

import de from '@/i18n/locales/de.json'
import en from '@/i18n/locales/en.json'
import {
  BUILD_TRAITS,
  isAdvantage,
  offerableTraits,
  splitTraits,
  TRAIT_PROFESSION,
  type TraitDefinition,
} from './character'
import { skillLabel } from './skills'

const trait = (over: Partial<TraitDefinition> = {}): TraitDefinition => ({
  cost: 0,
  uiName: null,
  icon: null,
  xpBoosts: {},
  professionTrait: false,
  exclusive: [],
  ...over,
})

/**
 * The sign of `cost` decides the whole colouring, and it is the one
 * thing here that cannot be reasoned out — it was read from the game's
 * own generated script files, where athletic is +10 and deaf is −12.
 */
describe('good and bad traits', () => {
  it('treats a positive cost as an advantage', () => {
    expect(isAdvantage(trait({ cost: 10 }))).toBe(true)
    expect(isAdvantage(trait({ cost: 4 }))).toBe(true)
  })

  it('treats a negative cost as a drawback', () => {
    expect(isAdvantage(trait({ cost: -10 }))).toBe(false)
    expect(isAdvantage(trait({ cost: -12 }))).toBe(false)
  })

  /**
   * The definitions the panel ships must agree with the game, or every
   * badge is coloured backwards. These four came from the installation.
   */
  it('agrees with the game on four known traits', () => {
    const php = readFileSync(
      '../backend/src/Server/Players/Character/CharacterDefinitions.php',
      'utf8',
    )

    const costOf = (id: string) => {
      const block = php.slice(php.indexOf(`'${id}' => [`))

      return Number(/'cost' => (-?\d+)/.exec(block)?.[1])
    }

    expect(costOf('athletic')).toBe(10)
    expect(costOf('strong')).toBe(10)
    expect(costOf('weak')).toBe(-10)
    expect(costOf('deaf')).toBe(-12)
  })
})

describe('splitting a player traits', () => {
  const table: Record<string, TraitDefinition> = {
    athletic: trait({ cost: 10 }),
    weak: trait({ cost: -10 }),
    burglar: trait({ cost: 0, professionTrait: true }),
  }

  it('sorts each side by how much it matters', () => {
    const table2 = { ...table, brave: trait({ cost: 4 }), deaf: trait({ cost: -12 }) }
    const split = splitTraits(['brave', 'athletic', 'weak', 'deaf'], table2)

    expect(split.good.map((t) => t.id)).toEqual(['athletic', 'brave'])
    expect(split.bad.map((t) => t.id)).toEqual(['deaf', 'weak'])
  })

  /** A job's trait is not a choice, so it must not sit with the chosen. */
  it('keeps a profession trait apart', () => {
    const split = splitTraits(['athletic', 'burglar'], table)

    expect(split.fromProfession.map((t) => t.id)).toEqual(['burglar'])
    expect(split.good.map((t) => t.id)).toEqual(['athletic'])
  })

  /** A mod defines its own; dropping it would hide part of the sheet. */
  it('still lists a trait it does not know', () => {
    expect(splitTraits(['someModTrait'], table).unknown).toEqual(['someModTrait'])
  })
})

describe('what can still be chosen', () => {
  const table: Record<string, TraitDefinition> = {
    athletic: trait({ cost: 10, exclusive: ['unfit'] }),
    unfit: trait({ cost: -6, exclusive: ['athletic'] }),
    brave: trait({ cost: 4 }),
    burglar: trait({ cost: 0, professionTrait: true }),
  }

  it('leaves out what is held already', () => {
    expect(offerableTraits(['brave'], table).map((t) => t.id)).not.toContain('brave')
  })

  /** The game forbids these pairs; offering one is offering a failure. */
  it('leaves out what the held traits exclude', () => {
    expect(offerableTraits(['athletic'], table).map((t) => t.id)).not.toContain('unfit')
  })

  /**
   * The game's own admin window offers a profession trait like any
   * other — `ISPlayerStatsChooseTraitUI.lua:26` filters on
   * `not hasTrait(...)` and nothing else. Granting one is how an
   * operator gives a second job's benefits, since a character can only
   * hold one profession.
   */
  it('offers a profession trait, as the game does', () => {
    expect(offerableTraits([], table).map((t) => t.id)).toContain('burglar')
  })

  /**
   * The build traits are the exception: the game recomputes them from
   * the character's weight, so a chip contradicting the weight slider
   * beside it is a state the game overrules on its own.
   */
  it('never offers a trait the weight drives', () => {
    const withBuild: Record<string, TraitDefinition> = {
      ...table,
      obese: trait({ cost: -6 }),
      underweight: trait({ cost: -4 }),
    }

    const offered = offerableTraits([], withBuild).map((t) => t.id)

    for (const id of BUILD_TRAITS) {
      expect(offered).not.toContain(id)
    }
  })

  it('offers the best first', () => {
    expect(offerableTraits([], table).map((t) => t.id)).toEqual([
      'athletic',
      'brave',
      'burglar',
      'unfit',
    ])
  })
})

/**
 * The names are the game's own, pulled from its DE and EN translation
 * files. A missing one would show a bare identifier.
 */
describe('the game names', () => {
  it('names every profession and trait in both locales', () => {
    const php = readFileSync(
      '../backend/src/Server/Players/Character/CharacterDefinitions.php',
      'utf8',
    )

    const section = (name: string) =>
      php.slice(php.indexOf(`const ${name} = [`), php.indexOf('];', php.indexOf(`const ${name} = [`)))

    const idsOf = (name: string) =>
      [...section(name).matchAll(/^\s{8}'([^']+)' => \[/gm)].map((m) => m[1])

    const professions = idsOf('PROFESSIONS')
    const traits = idsOf('TRAITS')

    expect(professions.length).toBeGreaterThan(20)
    expect(traits.length).toBeGreaterThan(90)

    for (const id of professions) {
      expect((de.character.profession as Record<string, string>)[id], `de profession ${id}`).toBeTruthy()
      expect((en.character.profession as Record<string, string>)[id], `en profession ${id}`).toBeTruthy()
    }

    for (const id of traits) {
      expect((de.character.trait as Record<string, string>)[id], `de trait ${id}`).toBeTruthy()
      expect((en.character.trait as Record<string, string>)[id], `en trait ${id}`).toBeTruthy()
    }
  })
})

/**
 * The XP boosts name a perk by its id (`Lightfoot`), while the locale is
 * keyed on the display name (`Lightfooted`) — so every boost has to
 * survive `skillLabel`. Three of them showed untranslated on screen
 * before this was routed through it.
 */
describe('the XP boosts', () => {
  it('names every boosted skill in both locales', () => {
    const php = readFileSync(
      '../backend/src/Server/Players/Character/CharacterDefinitions.php',
      'utf8',
    )

    const boosted = new Set(
      [...php.matchAll(/'xpBoosts' => \[([^\]]*)\]/g)].flatMap((match) =>
        [...(match[1] ?? '').matchAll(/'([A-Za-z]+)' =>/g)].map((inner) => inner[1] as string),
      ),
    )

    expect(boosted.size).toBeGreaterThan(20)

    for (const perk of boosted) {
      const label = skillLabel(perk)

      expect(
        (de.players.skillName as Record<string, string>)[label],
        `${perk} (as ${label}) has no German name`,
      ).toBeTruthy()
      expect(
        (en.players.skillName as Record<string, string>)[label] ?? label,
        `${perk} has no English fallback`,
      ).toBeTruthy()
    }
  })
})

/**
 * Each profession trait must name the job that grants it, or the chooser
 * shows names like "cook2" with nothing to explain them.
 */
describe('the profession traits', () => {
  it('maps every one to a job the locale can name', () => {
    const php = readFileSync(
      '../backend/src/Server/Players/Character/CharacterDefinitions.php',
      'utf8',
    )

    const section = php.slice(php.indexOf('const TRAITS = ['))

    const professionTraits = [...section.matchAll(/^\s{8}'([^']+)' => \[([\s\S]*?)^\s{8}\],/gm)]
      .filter(([, , body]) => /'professionTrait' => true/.test(body ?? ''))
      .map(([, id]) => id as string)

    expect(professionTraits.length).toBeGreaterThan(10)

    const build = new Set<string>(BUILD_TRAITS)

    for (const id of professionTraits) {
      if (build.has(id)) {
        continue
      }

      const job = TRAIT_PROFESSION[id]

      // A trait with no job and no build role would appear unlabelled.
      if (job === undefined) {
        continue
      }

      expect(
        (de.character.profession as Record<string, string>)[job],
        `${id} points at ${job}, which has no German name`,
      ).toBeTruthy()
    }
  })

  it('points every mapping at a real profession', () => {
    const jobs = new Set(Object.keys(de.character.profession as Record<string, string>))

    for (const [traitId, job] of Object.entries(TRAIT_PROFESSION)) {
      expect(jobs.has(job), `${traitId} points at unknown profession ${job}`).toBe(true)
    }
  })
})
