import { describe, expect, it } from 'vitest'

import {
  choicesOf,
  countIn,
  isEditable,
  labelOf,
  matches,
  outsideBounds,
  parseInput,
  tooltipOf,
  type ConfigValue,
} from './config'

function value(overrides: Partial<ConfigValue> = {}): ConfigValue {
  return {
    key: 'ZombieLore.Speed',
    section: 'ZombieLore',
    value: 2,
    type: 'enum',
    known: true,
    group: 'Zombie',
    min: 1,
    max: 4,
    numValues: 4,
    labels: { EN: 'Speed', DE: 'Geschwindigkeit:' },
    tooltips: { EN: 'Controls zombie movement.', DE: 'Steuert die Bewegung.' },
    choices: {
      EN: { '1': 'Sprinters', '2': 'Fast Shamblers', '3': 'Shamblers', '4': 'Random' },
      DE: { '1': 'Sprinter', '2': 'Schnelle Schlurfer', '3': 'Schlurfer', '4': 'zufällig' },
    },
    default: 4,
    defaultIsGenerated: false,
    outOfRange: false,
    ...overrides,
  }
}

describe('the label', () => {
  it('prefers the language being shown', () => {
    expect(labelOf(value(), 'de')).toBe('Geschwindigkeit')
    expect(labelOf(value(), 'en')).toBe('Speed')
  })

  /**
   * 80 of the game's labels end in a colon, because on its screen they
   * sit left of the control. Here they sit above it.
   */
  it('drops the colon the game puts on its own labels', () => {
    expect(labelOf(value({ labels: { EN: 'Day Length:', DE: 'Tageslänge:' } }), 'de')).toBe(
      'Tageslänge',
    )
  })

  it('falls back to English before the key', () => {
    expect(labelOf(value({ labels: { EN: 'Speed', DE: null } }), 'de')).toBe('Speed')
  })

  /**
   * The game translates none of the INI options and 17 sandbox ones,
   * so the panel supplies those names itself.
   */
  it('uses the panel’s own name where the game has none', () => {
    const untranslated = value({ key: 'ClayLakeChance', labels: null })

    expect(labelOf(untranslated, 'de')).toBe('Ton an Seen')
    expect(labelOf(untranslated, 'en')).toBe('Clay by lakes')
  })

  /**
   * A value a mod adds cannot be in any table, so it shows its key.
   * That is the honest answer, and the row marks it as a mod's.
   */
  it('falls back to the key for an option nobody has named', () => {
    expect(labelOf(value({ key: 'SomeModOption', labels: null, known: false }), 'de')).toBe(
      'SomeModOption',
    )
  })

  /** The game's own wording wins: it is what a player already knows. */
  it('prefers the game’s translation over the panel’s', () => {
    const both = value({ key: 'ClayLakeChance', labels: { EN: 'Clay', DE: 'Spielwort' } })

    expect(labelOf(both, 'de')).toBe('Spielwort')
  })
})

describe('the tooltip', () => {
  it('is null when the game has none, rather than empty', () => {
    expect(tooltipOf(value({ tooltips: null }), 'de')).toBeNull()
  })

  /**
   * 27 of the game's explanations carry `<br>` as a line break. Shown
   * verbatim it reads as a mistake in our interface.
   */
  it('turns the game’s own markup into a real line break', () => {
    const withMarkup = value({
      tooltips: { EN: 'First line. <br>WARNING: second line.', DE: null },
    })

    expect(tooltipOf(withMarkup, 'en')).toBe('First line.\nWARNING: second line.')
  })

  it('unescapes the quotes the translations carry', () => {
    const escaped = value({ tooltips: { EN: 'Sets \\"Population\\" to zero.', DE: null } })

    expect(tooltipOf(escaped, 'en')).toBe('Sets "Population" to zero.')
  })
})

describe('the choices', () => {
  it('are one-based, as the file stores them', () => {
    expect(choicesOf(value(), 'de')).toEqual([
      { value: 1, label: 'Sprinter' },
      { value: 2, label: 'Schnelle Schlurfer' },
      { value: 3, label: 'Schlurfer' },
      { value: 4, label: 'zufällig' },
    ])
  })

  /** Showing the number is honest; inventing a name for it is not. */
  it('fall back to the bare number where a label is missing', () => {
    const partial = value({ choices: { EN: { '1': 'One' }, DE: { '1': 'Eins' } } })

    expect(choicesOf(partial, 'de').map((choice) => choice.label)).toEqual(['Eins', '2', '3', '4'])
  })

  it('are empty for a value that is not an enum', () => {
    expect(choicesOf(value({ type: 'boolean', numValues: null }), 'de')).toEqual([])
  })
})

describe('the search', () => {
  it('matches the technical key, which is what a forum post names', () => {
    expect(matches(value(), 'zombielore', 'de')).toBe(true)
  })

  it('matches the translated label', () => {
    expect(matches(value(), 'geschwind', 'de')).toBe(true)
  })

  it('matches the explanation', () => {
    expect(matches(value(), 'bewegung', 'de')).toBe(true)
  })

  it('keeps everything when nothing is typed', () => {
    expect(matches(value(), '', 'de')).toBe(true)
  })

  it('rejects what appears nowhere', () => {
    expect(matches(value(), 'kochen', 'de')).toBe(false)
  })
})

describe('the group count', () => {
  /**
   * Counted against the file rather than the schema: a group may list
   * an option this server does not hold, and claiming it does is a
   * number that misleads.
   */
  it('counts only what the file actually holds', () => {
    const group = { name: 'Zombie', options: ['ZombieLore.Speed', 'Absent'] }

    expect(countIn(group, [value()])).toBe(1)
  })
})

describe('parsing what somebody typed', () => {
  it('reads an integer', () => {
    expect(parseInput(value({ type: 'integer' }), '42')).toBe(42)
    expect(parseInput(value({ type: 'integer' }), '-1')).toBe(-1)
  })

  /** Writing a zero the operator did not type is worse than refusing. */
  it('refuses text that is not an integer', () => {
    expect(parseInput(value({ type: 'integer' }), 'abc')).toBeNull()
    expect(parseInput(value({ type: 'integer' }), '')).toBeNull()
    expect(parseInput(value({ type: 'integer' }), '1.5')).toBeNull()
  })

  it('reads a decimal, and accepts a German comma', () => {
    expect(parseInput(value({ type: 'double' }), '0.6')).toBe(0.6)
    expect(parseInput(value({ type: 'double' }), '0,6')).toBe(0.6)
  })

  it('refuses text that is not a number', () => {
    expect(parseInput(value({ type: 'double' }), 'viel')).toBeNull()
  })

  it('reads a boolean from the two strings a select can carry', () => {
    expect(parseInput(value({ type: 'boolean' }), 'true')).toBe(true)
    expect(parseInput(value({ type: 'boolean' }), 'false')).toBe(false)
  })

  it('passes a string through unchanged, spaces included', () => {
    expect(parseInput(value({ type: 'string' }), 'Base.Hat, Base.Worm')).toBe('Base.Hat, Base.Worm')
  })
})

describe('the bounds check', () => {
  /** The game clamps silently, so a refusal here is the only warning. */
  it('catches a number the game would clamp', () => {
    expect(outsideBounds(value({ min: 1, max: 6 }), 7)).toBe(true)
    expect(outsideBounds(value({ min: 1, max: 6 }), 0)).toBe(true)
    expect(outsideBounds(value({ min: 1, max: 6 }), 6)).toBe(false)
  })

  it('does not judge a value with no bounds', () => {
    expect(outsideBounds(value({ min: null, max: null }), 999)).toBe(false)
  })

  it('does not judge a string', () => {
    expect(outsideBounds(value({ type: 'string' }), 'anything')).toBe(false)
  })
})

describe('what can be edited', () => {
  it('offers a control for the types that have one', () => {
    for (const type of ['boolean', 'integer', 'double', 'enum', 'string'] as const) {
      expect(isEditable(value({ type })), type).toBe(true)
    }
  })

  /** Multi-line prose needs more than a single-line field. */
  it('leaves multi-line text to a later step rather than faking a field', () => {
    expect(isEditable(value({ type: 'text' }))).toBe(false)
  })
})
