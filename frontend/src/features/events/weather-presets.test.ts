import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

import { actionsOf, PRESET_GROUPS, presetsOf, WEATHER_PRESETS } from './weather-presets'

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
          // A toggle is a kind, not an amount, and declares no bounds to
          // stay inside.
          if (typeof value === 'boolean') {
            expect(
              block.slice(0, 400),
              `${step.action}.${field} is set as a boolean but is not a toggle`,
            ).toContain(`EventField::toggle('${field}'`)

            continue
          }

          // Two shapes declare a bounded number: the general
          // `number(name, min, max, default)` and the `percent()`
          // shorthand, whose bounds are 0..100 unless it names others.
          // A bound may also be a constant rather than a literal.
          const declared = block.slice(0, 400)

          const asNumber = new RegExp(
            `EventField::number\\('${field}', (self::[A-Z_]+|-?\\d+), (self::[A-Z_]+|-?\\d+)`,
          ).exec(declared)

          const asPercent = new RegExp(
            `EventField::percent\\('${field}', -?\\d+(?:, min: (-?\\d+))?(?:, max: (-?\\d+))?`,
          ).exec(declared)

          expect(
            asNumber ?? asPercent,
            `${step.action}.${field} declares no bounds`,
          ).not.toBeNull()

          const [min, declaredMax] =
            asNumber !== null
              ? [asNumber[1], asNumber[2]]
              : [(asPercent as RegExpExecArray)[1] ?? '0', (asPercent as RegExpExecArray)[2] ?? '100']

          // A bound may be a named constant on either side, so both are
          // resolved from the catalogue's own declaration.
          const resolve = (bound: string): string =>
            bound.startsWith('self::')
              ? (new RegExp(`public const ${bound.slice(6)} = (-?\\d+)`).exec(
                  catalogue,
                ) as RegExpExecArray)[1]
              : bound

          const max = resolve(declaredMax)

          expect(value, `${preset.id}: ${step.action}.${field}`).toBeGreaterThanOrEqual(
            Number(resolve(min)),
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
   * The groups decide four rows on the page, so a preset in no group or
   * a group with nothing in it would be an empty heading.
   */
  it('puts every preset in a declared group', () => {
    for (const preset of WEATHER_PRESETS) {
      expect(PRESET_GROUPS, `${preset.id} is in no group`).toContain(preset.group)
    }
  })

  it('leaves no group empty', () => {
    for (const group of PRESET_GROUPS) {
      expect(presetsOf(group), `${group} holds nothing`).not.toHaveLength(0)
    }
  })

  it('labels every group in both locales', () => {
    const de = JSON.parse(readFileSync('src/i18n/locales/de.json', 'utf8'))
    const en = JSON.parse(readFileSync('src/i18n/locales/en.json', 'utf8'))

    for (const group of PRESET_GROUPS) {
      expect(de.events.presetGroups[group], `${group} missing from de`).toBeTruthy()
      expect(en.events.presetGroups[group], `${group} missing from en`).toBeTruthy()
    }
  })

  /**
   * Snow is a type and a fall, and setting the type alone changes
   * nothing visible — so the preset that offers snow has to do both.
   */
  it('makes the snow preset actually snow', () => {
    const snow = WEATHER_PRESETS.find((preset) => preset.id === 'snow')

    expect(snow).toBeDefined()
    expect(actionsOf(snow as (typeof WEATHER_PRESETS)[number])).toContain('setSnow')
    expect(actionsOf(snow as (typeof WEATHER_PRESETS)[number])).toContain('startRain')
  })

  /**
   * The game refuses snow above freezing, so a preset asking for it
   * before the temperature drops is a preset asking to be refused. The
   * steps fire in order, so the cold has to be declared first.
   */
  it('cools below freezing before it asks for snow', () => {
    for (const id of ['snow', 'blizzard']) {
      const preset = WEATHER_PRESETS.find((candidate) => candidate.id === id)

      expect(preset, `${id} is missing`).toBeDefined()

      const steps = (preset as (typeof WEATHER_PRESETS)[number]).steps
      const cold = steps.findIndex((step) => step.action === 'setTemperature')

      expect(cold, `${id} never sets a temperature`).toBeGreaterThanOrEqual(0)
      expect(
        steps[cold]?.inputs?.value,
        `${id} does not cool below freezing`,
      ).toBeLessThan(0)

      // Every snow-making step must come after the cold.
      for (const [at, step] of steps.entries()) {
        if (step.action === 'setSnow' || step.action === 'startBlizzard') {
          expect(at, `${id} asks for snow before it cools`).toBeGreaterThan(cold)
        }
      }
    }
  })

  /**
   * The snow presets pin a temperature below freezing, and a pinned
   * climate value stays pinned until something releases it — so clear
   * has to hand the temperature back rather than leaving July at −12.
   * It releases rather than setting a number because only the game knows
   * what the season's own temperature is.
   */
  it('hands the temperature back to the game when clearing', () => {
    const clear = WEATHER_PRESETS.find((preset) => preset.id === 'clear')

    expect(clear).toBeDefined()
    expect(actionsOf(clear as (typeof WEATHER_PRESETS)[number])).toContain('releaseTemperature')

    // And never by setting one of its own, which would be a guess
    // overriding the game's.
    expect(actionsOf(clear as (typeof WEATHER_PRESETS)[number])).not.toContain('setTemperature')
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
