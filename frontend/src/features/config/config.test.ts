import { describe, expect, it } from 'vitest'

import { choicesOf, countIn, labelOf, matches, tooltipOf, type ConfigValue } from './config'

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
    expect(labelOf(value(), 'de')).toBe('Geschwindigkeit:')
    expect(labelOf(value(), 'en')).toBe('Speed')
  })

  it('falls back to English before the key', () => {
    expect(labelOf(value({ labels: { EN: 'Speed', DE: null } }), 'de')).toBe('Speed')
  })

  /** The INI has no translated names, so its rows show the key. */
  it('shows the key when the game translates nothing', () => {
    expect(labelOf(value({ key: 'PVP', labels: null }), 'de')).toBe('PVP')
  })
})

describe('the tooltip', () => {
  it('is null when the game has none, rather than empty', () => {
    expect(tooltipOf(value({ tooltips: null }), 'de')).toBeNull()
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
