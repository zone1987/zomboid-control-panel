import { describe, expect, it } from 'vitest'

import type { ConfigValue } from './config'
import { pendingFrom, rowsFor } from './use-config-draft'

function value(overrides: Partial<ConfigValue> = {}): ConfigValue {
  return {
    key: 'MaxPlayers',
    section: null,
    value: 32,
    type: 'integer',
    known: true,
    group: 'Players',
    min: 1,
    max: 254,
    numValues: null,
    labels: null,
    tooltips: null,
    choices: null,
    default: 32,
    defaultIsGenerated: false,
    outOfRange: false,
    ...overrides,
  }
}

/** One typed edit, as the hook holds it. */
function edit(text: string, from: boolean | number | string = 32) {
  return { MaxPlayers: { text, from } }
}

describe('the row state', () => {
  it('shows the file value until something is typed', () => {
    const rows = rowsFor([value()], {})

    expect(rows.get('MaxPlayers')?.shown).toBe(32)
    expect(rows.get('MaxPlayers')?.changed).toBe(false)
    expect(pendingFrom(rows)).toEqual({})
  })

  it('collects a change and offers it for sending', () => {
    const rows = rowsFor([value()], edit('16'))

    expect(rows.get('MaxPlayers')?.changed).toBe(true)
    expect(pendingFrom(rows)).toEqual({ MaxPlayers: 16 })
  })

  /** Typing back to the stored value is not a change to send. */
  it('drops an edit that ends up where it started', () => {
    const rows = rowsFor([value()], edit('32'))

    expect(rows.get('MaxPlayers')?.changed).toBe(false)
    expect(pendingFrom(rows)).toEqual({})
  })

  /** "1." has to stay typeable on the way to "1.5". */
  it('keeps a half-typed number as text', () => {
    const rows = rowsFor([value({ type: 'double', value: 1 })], edit('1.', 1))

    expect(rows.get('MaxPlayers')?.shown).toBe('1.')
  })

  it('never sends text that is not a value of the type', () => {
    const rows = rowsFor([value()], edit('abc'))

    expect(rows.get('MaxPlayers')?.invalid).toBe(true)
    expect(pendingFrom(rows)).toEqual({})
  })

  /** The game clamps silently, so a refusal here is the only warning. */
  it('never sends a number outside the game’s own bounds', () => {
    const rows = rowsFor([value()], edit('999'))

    expect(rows.get('MaxPlayers')?.outOfBounds).toBe(true)
    expect(pendingFrom(rows)).toEqual({})
  })

  /**
   * The reason an edit is held against its own value: a query that
   * refetches while somebody types must not overwrite the field.
   */
  it('keeps what was typed when the file still holds the same value', () => {
    const rows = rowsFor([value()], edit('16'))

    expect(rows.get('MaxPlayers')?.shown).toBe('16')
  })

  /** But an edit against a value that moved is not one anybody meant. */
  it('drops an edit when the underlying value changed', () => {
    const rows = rowsFor([value({ value: 64 })], edit('16', 32))

    expect(rows.get('MaxPlayers')?.shown).toBe(64)
    expect(pendingFrom(rows)).toEqual({})
  })

  it('treats a boolean as the switch state rather than as text', () => {
    const rows = rowsFor(
      [value({ key: 'PVP', value: true, type: 'boolean', min: null, max: null })],
      { PVP: { text: 'false', from: true } },
    )

    expect(rows.get('PVP')?.shown).toBe(false)
    expect(pendingFrom(rows)).toEqual({ PVP: false })
  })

  /** 1 against 1.0 is the same value, and must not read as a change. */
  it('does not call a float equal to its integer a change', () => {
    const rows = rowsFor([value({ type: 'double', value: 1 })], edit('1.0', 1))

    expect(rows.get('MaxPlayers')?.changed).toBe(false)
  })

  it('collects several changes at once', () => {
    const rows = rowsFor(
      [value(), value({ key: 'PVP', value: true, type: 'boolean', min: null, max: null })],
      { ...edit('16'), PVP: { text: 'false', from: true } },
    )

    expect(pendingFrom(rows)).toEqual({ MaxPlayers: 16, PVP: false })
  })

  /** An unusable row must not take the usable ones down with it. */
  it('still offers the good changes beside a bad one', () => {
    const rows = rowsFor(
      [value(), value({ key: 'PVP', value: true, type: 'boolean', min: null, max: null })],
      { ...edit('abc'), PVP: { text: 'false', from: true } },
    )

    expect(pendingFrom(rows)).toEqual({ PVP: false })
  })
})
