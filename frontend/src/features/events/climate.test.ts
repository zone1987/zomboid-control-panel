import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

import {
  CLIMATE_DIALS,
  CLIMATE_GROUPS,
  dialsOf,
  displayBounds,
  toClimate,
  toDisplay,
  unitOf,
  type ClimateValue,
} from './climate'

const value = (over: Partial<ClimateValue> = {}): ClimateValue => ({
  name: 'x',
  index: 0,
  value: 0,
  pinned: false,
  pinnedValue: 0,
  min: 0,
  max: 1,
  ...over,
})

/**
 * The bridge reports thirteen floats by name and the panel names them
 * again to say which unit each is read in. A name in one and not the
 * other is a row that silently never appears.
 */
describe('the climate dials against the bridge', () => {
  const lua = readFileSync('../backend/resources/bridge/ZomboidControlBridge.lua', 'utf8')

  const inBridge = new Set(
    [...(/local CLIMATE_FLOATS = \{([\s\S]*?)\}/.exec(lua) as RegExpExecArray)[1].matchAll(
      /"(\w+)"/g,
    )].map((match) => match[1]),
  )

  it('reads the bridge table at all', () => {
    expect(inBridge.size).toBe(13)
  })

  it('names every float the bridge reports', () => {
    for (const name of inBridge) {
      expect(
        CLIMATE_DIALS.map((dial) => dial.name),
        `${name} is reported but has no dial`,
      ).toContain(name)
    }
  })

  it('names nothing the bridge does not report', () => {
    for (const dial of CLIMATE_DIALS) {
      expect(inBridge, `${dial.name} has a dial but is never reported`).toContain(dial.name)
    }
  })

  it('puts every dial in a declared group, and leaves none empty', () => {
    for (const dial of CLIMATE_DIALS) {
      expect(CLIMATE_GROUPS, `${dial.name} is in no group`).toContain(dial.group)
    }

    for (const group of CLIMATE_GROUPS) {
      expect(dialsOf(group), `${group} holds nothing`).not.toHaveLength(0)
    }
  })

  it('labels every value and group in both locales', () => {
    const de = JSON.parse(readFileSync('src/i18n/locales/de.json', 'utf8'))
    const en = JSON.parse(readFileSync('src/i18n/locales/en.json', 'utf8'))

    for (const dial of CLIMATE_DIALS) {
      expect(de.climate.values[dial.name], `${dial.name} missing from de`).toBeTruthy()
      expect(en.climate.values[dial.name], `${dial.name} missing from en`).toBeTruthy()
    }

    for (const group of CLIMATE_GROUPS) {
      expect(de.climate.groups[group], `${group} missing from de`).toBeTruthy()
      expect(en.climate.groups[group], `${group} missing from en`).toBeTruthy()
    }
  })

  /**
   * A dial claiming an action the catalogue does not declare would offer
   * a Set button that 404s.
   */
  it('names only actions the catalogue declares', () => {
    const catalogue = readFileSync('../backend/src/Server/Events/EventCatalogue.php', 'utf8')

    const declared = new Set(
      [...catalogue.matchAll(/new EventAction\('([a-zA-Z]+)'/g)].map((match) => match[1]),
    )

    for (const dial of CLIMATE_DIALS) {
      if (dial.action !== undefined) {
        expect(declared, `${dial.name} names ${dial.action}`).toContain(dial.action)
      }
    }
  })
})

/**
 * The conversion is where a wrong assumption is invisible: the game
 * clamps `setAdminValue` silently, so an out-of-range number is applied
 * as something else and reported as a success.
 */
describe('showing a climate value in the unit it is read in', () => {
  const dial = (over: Partial<(typeof CLIMATE_DIALS)[number]>) =>
    ({ name: 'x', scale: 'percent', group: 'air', ...over }) as (typeof CLIMATE_DIALS)[number]

  it('shows a 0..1 float as a percentage', () => {
    expect(toDisplay(dial({ scale: 'percent' }), 0.8, 120, 1)).toBe(80)
    expect(toClimate(dial({ scale: 'percent' }), 80, 120, 1)).toBeCloseTo(0.8)
  })

  /**
   * View distance runs 0..100 in the game, so a fixed factor of 100
   * would show it as 10000 %. The share is taken against the float's own
   * maximum, which the game reports.
   */
  it('scales a percentage against the float own range, not a fixed 100', () => {
    expect(toDisplay(dial({ scale: 'percent' }), 50, 120, 100)).toBe(50)
    expect(toClimate(dial({ scale: 'percent' }), 50, 120, 100)).toBeCloseTo(50)
  })

  it('shows wind against the ceiling the game reports', () => {
    expect(toDisplay(dial({ scale: 'kph' }), 0.5, 120)).toBe(60)
    expect(toClimate(dial({ scale: 'kph' }), 60, 120)).toBeCloseTo(0.5)
  })

  /** A ceiling of zero must not divide, whatever the bridge answered. */
  it('survives a zero wind ceiling', () => {
    expect(toClimate(dial({ scale: 'kph' }), 60, 0)).toBe(0)
  })

  it('leaves a temperature in degrees on both sides', () => {
    expect(toDisplay(dial({ scale: 'celsius' }), -5.04, 120)).toBe(-5)
    expect(toClimate(dial({ scale: 'celsius' }), -5, 120)).toBe(-5)
  })

  it('round-trips every scale', () => {
    for (const scale of ['percent', 'celsius', 'kph', 'raw'] as const) {
      const shown = toDisplay(dial({ scale }), 0.5, 120, 1)

      expect(
        toDisplay(dial({ scale }), toClimate(dial({ scale }), shown, 120, 1), 120, 1),
        scale,
      ).toBeCloseTo(shown, 1)
    }
  })

  it('states a unit for everything but a bare number', () => {
    expect(unitOf(dial({ scale: 'percent' }))).toBe('%')
    expect(unitOf(dial({ scale: 'celsius' }))).toBe('°C')
    expect(unitOf(dial({ scale: 'kph' }))).toBe('km/h')
    expect(unitOf(dial({ scale: 'raw' }))).toBeUndefined()
  })

  /**
   * The bounds come off the reading, so a float the game declares as
   * −80..80 gives a slider of −80..80 rather than the panel's guess.
   */
  it('takes the slider bounds from what the game reported', () => {
    expect(
      displayBounds(dial({ scale: 'celsius' }), value({ min: -80, max: 80 }), 120),
    ).toEqual({ min: -80, max: 80 })

    expect(displayBounds(dial({ scale: 'raw' }), value({ min: -1, max: 1 }), 120)).toEqual({
      min: -1,
      max: 1,
    })
  })
})
