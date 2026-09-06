import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

import { actionsOf, WEATHER_PRESETS } from './weather-presets'

/**
 * A preset is a bundle of actions the catalogue already declares, so it
 * adds no capability and cannot drift from the backend — provided every
 * step names an action that exists and stays inside its bounds.
 */
describe('the weather presets against the catalogue', () => {
  const catalogue = readFileSync('../backend/src/Server/Events/EventCatalogue.php', 'utf8')

  const declared = new Set(
    [...catalogue.matchAll(/new EventAction\('([a-zA-Z]+)'/g)].map((match) => match[1]),
  )

  it('reads the catalogue at all', () => {
    expect(declared.size).toBeGreaterThan(20)
  })

  it('names only actions the catalogue declares', () => {
    for (const preset of WEATHER_PRESETS) {
      for (const action of actionsOf(preset)) {
        expect(declared, `${preset.id} names ${action}`).toContain(action)
      }
    }
  })

  it('uses only weather and bridge actions', () => {
    // A preset that reached into another category would put a horde
    // behind an innocuous-looking cloud icon.
    for (const preset of WEATHER_PRESETS) {
      for (const action of actionsOf(preset)) {
        const block = catalogue.slice(catalogue.indexOf(`new EventAction('${action}'`))

        expect(
          block.slice(0, 200),
          `${action} in ${preset.id} is not a weather action`,
        ).toContain('CATEGORY_WEATHER')
      }
    }
  })

  it('stays inside the bounds each field declares', () => {
    for (const preset of WEATHER_PRESETS) {
      for (const step of preset.steps) {
        const block = catalogue.slice(
          catalogue.indexOf(`new EventAction('${step.action}'`),
        )

        for (const [field, value] of Object.entries(step.inputs ?? {})) {
          // A bound may be a constant rather than a literal, so both
          // shapes are read and a named one resolved from its own
          // declaration.
          const bounds = new RegExp(
            `EventField::number\\('${field}', (-?\\d+), (self::[A-Z_]+|-?\\d+)`,
          ).exec(block.slice(0, 400))

          expect(bounds, `${step.action}.${field} declares no bounds`).not.toBeNull()

          const [, min, declaredMax] = bounds as RegExpExecArray

          const max = declaredMax.startsWith('self::')
            ? (new RegExp(
                `public const ${declaredMax.slice(6)} = (\\d+)`,
              ).exec(catalogue) as RegExpExecArray)[1]
            : declaredMax

          expect(value, `${preset.id}: ${step.action}.${field}`).toBeGreaterThanOrEqual(
            Number(min),
          )
          expect(value, `${preset.id}: ${step.action}.${field}`).toBeLessThanOrEqual(Number(max))
        }
      }
    }
  })

  it('sets at most one value per step', () => {
    // The page keys a value by the step's first input, so a second one
    // would be silently dropped.
    for (const preset of WEATHER_PRESETS) {
      for (const step of preset.steps) {
        expect(
          Object.keys(step.inputs ?? {}).length,
          `${preset.id}: ${step.action}`,
        ).toBeLessThanOrEqual(1)
      }
    }
  })

  it('labels every preset in both locales', () => {
    const de = JSON.parse(readFileSync('src/i18n/locales/de.json', 'utf8'))
    const en = JSON.parse(readFileSync('src/i18n/locales/en.json', 'utf8'))

    for (const preset of WEATHER_PRESETS) {
      expect(de.events.presets[preset.id], `${preset.id} missing from de`).toBeTruthy()
      expect(en.events.presets[preset.id], `${preset.id} missing from en`).toBeTruthy()
    }
  })

  /**
   * Clear is how the weather is ended, so it has to end all of it.
   * stopWeather stops the precipitation and leaves the wind, the clouds
   * and the fog where the last downpour put them — which is not clear.
   */
  it('clears everything, not only the precipitation', () => {
    const clear = WEATHER_PRESETS.find((preset) => preset.id === 'clear')

    expect(clear).toBeDefined()

    const steps = clear as (typeof WEATHER_PRESETS)[number]

    expect(actionsOf(steps)).toContain('stopWeather')

    for (const dial of ['setWind', 'setClouds', 'setFog']) {
      const step = steps.steps.find((candidate) => candidate.action === dial)

      expect(step, `clear does not reset ${dial}`).toBeDefined()
      expect(Object.values(step?.inputs ?? {})).toEqual([0])
    }
  })
})

/**
 * The page waits for the bridge's own write interval before refetching,
 * so the two numbers have to agree. A page waiting less would show the
 * old state and read as the command having failed.
 */
describe('the world write interval against the bridge', () => {
  it('matches SECONDS_BETWEEN_WORLD_WRITES in the Lua', () => {
    const lua = readFileSync('../backend/resources/bridge/ZomboidControlBridge.lua', 'utf8')
    const page = readFileSync('src/features/events/weather-page.tsx', 'utf8')

    const inLua = /local SECONDS_BETWEEN_WORLD_WRITES = (\d+)/.exec(lua)
    const inPage = /const BRIDGE_WORLD_WRITE_SECONDS = (\d+)/.exec(page)

    expect(inLua, 'the bridge no longer declares a world write interval').not.toBeNull()
    expect(inPage, 'the page no longer mirrors it').not.toBeNull()

    expect((inPage as RegExpExecArray)[1]).toBe((inLua as RegExpExecArray)[1])
  })

  /** Polling slower than the bridge writes wastes the shorter interval. */
  it('polls the world at least as often as the bridge writes it', () => {
    const lua = readFileSync('../backend/resources/bridge/ZomboidControlBridge.lua', 'utf8')
    const strip = readFileSync('src/features/servers/world-strip.tsx', 'utf8')

    const writes = Number(
      (/local SECONDS_BETWEEN_WORLD_WRITES = (\d+)/.exec(lua) as RegExpExecArray)[1],
    )
    const polls = Number(
      (/refetchInterval: ([\d_]+),/.exec(strip) as RegExpExecArray)[1].replace(/_/g, ''),
    )

    expect(polls).toBeLessThanOrEqual(writes * 2 * 1000)
  })
})
