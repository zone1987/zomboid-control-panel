import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

import {
  defaultsFor,
  EVENT_CATEGORIES,
  isComplete,
  type EventAction,
  type EventField,
} from './events'

function actionWith(...fields: EventField[]): EventAction {
  return {
    id: 'test-action',
    category: 'world',
    channel: 'rcon',
    commands: [],
    destructive: false,
    fields,
    available: true,
    missing: [],
  }
}

describe('defaultsFor', () => {
  it('uses the declared default', () => {
    const action = actionWith({ name: 'count', type: 'number', min: 1, max: 10, default: 4 })

    expect(defaultsFor(action)).toEqual({ count: 4 })
  })

  it('uses a declared default of any type', () => {
    const action = actionWith(
      { name: 'label', type: 'text', default: 'siren' },
      { name: 'loud', type: 'toggle', default: false },
    )

    expect(defaultsFor(action)).toEqual({ label: 'siren', loud: false })
  })

  it('falls back to min for a number without a default', () => {
    const action = actionWith({ name: 'radius', type: 'number', min: 25, max: 100 })

    expect(defaultsFor(action)).toEqual({ radius: 25 })
  })

  it('falls back to zero for a number with neither default nor min', () => {
    const action = actionWith({ name: 'offset', type: 'number' })

    expect(defaultsFor(action)).toEqual({ offset: 0 })
  })

  it('falls back to an empty string for a non-number without a default', () => {
    const action = actionWith(
      { name: 'player', type: 'player' },
      { name: 'reason', type: 'text' },
      { name: 'kind', type: 'choice', choices: ['a', 'b'] },
    )

    expect(defaultsFor(action)).toEqual({ player: '', reason: '', kind: '' })
  })

  it('returns nothing for an action without fields', () => {
    expect(defaultsFor(actionWith())).toEqual({})
  })
})

describe('isComplete', () => {
  it('accepts an action without fields', () => {
    expect(isComplete(actionWith(), {})).toBe(true)
  })

  it('refuses a required number that is empty', () => {
    const action = actionWith({ name: 'count', type: 'number', min: 1, max: 10, required: true })

    expect(isComplete(action, { count: '' })).toBe(false)
  })

  it('refuses a required number that is missing entirely', () => {
    const action = actionWith({ name: 'count', type: 'number', min: 1, max: 10 })

    expect(isComplete(action, {})).toBe(false)
  })

  it('refuses a required number below the declared minimum', () => {
    const action = actionWith({ name: 'count', type: 'number', min: 1, max: 10 })

    expect(isComplete(action, { count: 0 })).toBe(false)
  })

  it('refuses a required number above the declared maximum', () => {
    const action = actionWith({ name: 'count', type: 'number', min: 1, max: 10 })

    expect(isComplete(action, { count: 11 })).toBe(false)
  })

  it('accepts a required number inside the declared range', () => {
    const action = actionWith({ name: 'count', type: 'number', min: 1, max: 10 })

    expect(isComplete(action, { count: 5 })).toBe(true)
    expect(isComplete(action, { count: '5' })).toBe(true)
  })

  it('accepts an optional number that is empty', () => {
    const action = actionWith({ name: 'count', type: 'number', min: 1, max: 10, required: false })

    expect(isComplete(action, { count: '' })).toBe(true)
  })

  it('accepts an optional number that is missing entirely', () => {
    const action = actionWith({ name: 'count', type: 'number', min: 1, max: 10, required: false })

    expect(isComplete(action, {})).toBe(true)
  })

  it('refuses an optional number that is filled but out of range', () => {
    const action = actionWith({ name: 'count', type: 'number', min: 1, max: 10, required: false })

    expect(isComplete(action, { count: 99 })).toBe(false)
    expect(isComplete(action, { count: 0 })).toBe(false)
    expect(isComplete(action, { count: '99' })).toBe(false)
  })

  it('accepts an optional number that is filled and in range', () => {
    const action = actionWith({ name: 'count', type: 'number', min: 1, max: 10, required: false })

    expect(isComplete(action, { count: 7 })).toBe(true)
  })

  it('refuses an optional number that is filled with something unparseable', () => {
    const action = actionWith({ name: 'count', type: 'number', min: 1, max: 10, required: false })

    expect(isComplete(action, { count: 'abc' })).toBe(false)
  })

  it('refuses a required text field that is blank', () => {
    const action = actionWith({ name: 'reason', type: 'text' })

    expect(isComplete(action, { reason: '' })).toBe(false)
    expect(isComplete(action, { reason: '   ' })).toBe(false)
  })

  it('accepts a required text field that is filled', () => {
    const action = actionWith({ name: 'reason', type: 'text' })

    expect(isComplete(action, { reason: 'griefing' })).toBe(true)
  })

  it('accepts an optional text field that is blank', () => {
    const action = actionWith({ name: 'reason', type: 'text', required: false })

    expect(isComplete(action, { reason: '' })).toBe(true)
    expect(isComplete(action, { reason: '   ' })).toBe(true)
  })

  it('refuses a required player that has not been chosen', () => {
    const action = actionWith({ name: 'player', type: 'player' })

    expect(isComplete(action, { player: '' })).toBe(false)
  })

  it('refuses a required choice that has not been made', () => {
    const action = actionWith({ name: 'kind', type: 'choice', choices: ['rain', 'fog'] })

    expect(isComplete(action, { kind: '' })).toBe(false)
    expect(isComplete(action, { kind: 'fog' })).toBe(true)
  })

  it('accepts a toggle left false, since false is a chosen value', () => {
    const action = actionWith({ name: 'loud', type: 'toggle', required: false })

    expect(isComplete(action, { loud: false })).toBe(true)
  })

  it('demands every field, not just the first', () => {
    const action = actionWith(
      { name: 'player', type: 'player' },
      { name: 'count', type: 'number', min: 1, max: 10 },
    )

    expect(isComplete(action, { player: 'bob', count: '' })).toBe(false)
    expect(isComplete(action, { player: '', count: 5 })).toBe(false)
    expect(isComplete(action, { player: 'bob', count: 5 })).toBe(true)
  })

  it('accepts the defaults the catalogue itself declares', () => {
    const action = actionWith(
      { name: 'count', type: 'number', min: 1, max: 10, default: 3 },
      { name: 'kind', type: 'choice', choices: ['rain'], default: 'rain' },
    )

    expect(isComplete(action, defaultsFor(action))).toBe(true)
  })
})

/**
 * The frontend lists the categories to keep the order, the icons and the
 * typing local, so it can drift from the backend that assigns them.
 * Asserted against the source rather than trusted.
 */
describe('the category list against the backend', () => {
  const source = readFileSync('../backend/src/Server/Events/EventAction.php', 'utf8')

  it('matches EventAction::CATEGORIES', () => {
    const start = source.indexOf('public const CATEGORIES')
    const block = source.slice(start, source.indexOf('];', start))
    const names = [...block.matchAll(/self::CATEGORY_([A-Z]+),/g)].map((match) => match[1])

    const values = names.map((name) => {
      const found = source.match(new RegExp(`public const CATEGORY_${name} = '([a-z]+)'`))

      expect(found, `CATEGORY_${name} has no value`).not.toBeNull()

      return (found as RegExpMatchArray)[1]
    })

    expect(values).toEqual([...EVENT_CATEGORIES])
  })

  it('gives every category an icon and a label in both locales', () => {
    const page = readFileSync('src/features/events/events-page.tsx', 'utf8')
    const icons = page.slice(page.indexOf('const CATEGORY_ICONS'))
    const de = JSON.parse(readFileSync('src/i18n/locales/de.json', 'utf8'))
    const en = JSON.parse(readFileSync('src/i18n/locales/en.json', 'utf8'))

    for (const category of EVENT_CATEGORIES) {
      expect(icons, `${category} has no icon`).toMatch(new RegExp(`\\b${category}:`))
      expect(de.events.categories[category], `${category} missing from de`).toBeTruthy()
      expect(en.events.categories[category], `${category} missing from en`).toBeTruthy()
    }
  })
})
