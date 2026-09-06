import { apiFetch } from '@/lib/api'
import { triggerEvent } from './events'

/** One of the thirteen climate floats, as the running game reports it. */
export type ClimateValue = {
  name: string
  index: number
  /** What the game is using. */
  value: number
  /** Whether an admin override is holding it there. */
  pinned: boolean
  /** What that override is set to; meaningful only when pinned. */
  pinnedValue: number
  min: number
  max: number
}

export type ClimateReading = {
  status: string
  values: Record<string, ClimateValue>
  precipitationIsSnow: { value: boolean; admin: boolean; adminValue: boolean } | null
  windSpeedKph: number
  maxWindSpeedKph: number
  snowing: boolean
  raining: boolean
  thunderStorming: boolean
  season: string
  seasonProgression: number
}

export function readClimate(serverId: string): Promise<ClimateReading> {
  return apiFetch(`/servers/${serverId}/climate`)
}

/**
 * How each climate value is shown and set.
 *
 * The **bounds are not here** — every float reports its own, and three
 * of the thirteen do not run 0..1 (temperature −80..80, the wind angle
 * −1..1, view distance 0..100). A second copy of them in the panel would
 * be a table to keep in step with the game, and `setAdminValue` clamps
 * silently, so being wrong would set something other than what was asked
 * and report success.
 *
 * What is here is what the game cannot say: which unit a person reads it
 * in, and which action sets it.
 */
export type ClimateDial = {
  /** The name the bridge reports, and the key into `values`. */
  name: string
  /** The event action that sets it, where one exists. */
  action?: string
  /** How the number is shown: a share of the range, or as it stands. */
  scale: 'percent' | 'celsius' | 'kph' | 'raw'
  /** Which group it belongs to on the page. */
  group: ClimateGroup
}

export const CLIMATE_GROUPS = ['air', 'sky', 'light'] as const

export type ClimateGroup = (typeof CLIMATE_GROUPS)[number]

/**
 * All thirteen, in the order they are shown.
 *
 * Six have an action in the catalogue and can be set from here today.
 * The other seven are read-only until one exists — shown rather than
 * hidden, because "the game is running this at 0.4" is worth knowing
 * even where the panel cannot change it, and hiding a value nobody can
 * set is how a panel comes to lie about what the world is doing.
 */
export const CLIMATE_DIALS: ClimateDial[] = [
  { name: 'temperature', action: 'setTemperature', scale: 'celsius', group: 'air' },
  { name: 'wind', action: 'setWind', scale: 'kph', group: 'air' },
  { name: 'windAngle', scale: 'raw', group: 'air' },
  { name: 'humidity', scale: 'percent', group: 'air' },

  { name: 'clouds', action: 'setClouds', scale: 'percent', group: 'sky' },
  { name: 'fog', action: 'setFog', scale: 'percent', group: 'sky' },
  { name: 'precipitation', scale: 'percent', group: 'sky' },
  // Not a percent: this float runs 0..100 in the game, so a share of its
  // own range would multiply by 100 twice.
  { name: 'viewDistance', action: 'setViewDistance', scale: 'raw', group: 'sky' },

  { name: 'daylight', action: 'setDaylight', scale: 'percent', group: 'light' },
  { name: 'globalLight', scale: 'percent', group: 'light' },
  { name: 'nightStrength', scale: 'percent', group: 'light' },
  { name: 'ambient', scale: 'percent', group: 'light' },
  { name: 'desaturation', scale: 'percent', group: 'light' },
]

export function dialsOf(group: ClimateGroup): ClimateDial[] {
  return CLIMATE_DIALS.filter((dial) => dial.group === group)
}

/**
 * The game's number, in the unit a person reads.
 *
 * `percent` is a share of the float's **own** range rather than a
 * multiplication by 100: ten of the thirteen run 0..1 and one runs
 * 0..100, so a fixed factor would show view distance as 10000 %.
 * Wind needs the reading too — its climate value is a fraction of the
 * game's own ceiling, which the game reports rather than the panel
 * assuming 120.
 */
export function toDisplay(dial: ClimateDial, value: number, maxWindKph: number, max = 1): number {
  switch (dial.scale) {
    case 'celsius':
      return Math.round(value * 10) / 10
    case 'kph':
      return Math.round(value * maxWindKph)
    case 'raw':
      return Math.round(value * 100) / 100
    default:
      return max === 0 ? 0 : Math.round((value / max) * 100)
  }
}

/** And back again, for what is sent. */
export function toClimate(dial: ClimateDial, shown: number, maxWindKph: number, max = 1): number {
  switch (dial.scale) {
    case 'celsius':
      return shown
    case 'kph':
      return maxWindKph === 0 ? 0 : shown / maxWindKph
    case 'raw':
      return shown
    default:
      return (shown / 100) * max
  }
}

export function unitOf(dial: ClimateDial): string | undefined {
  switch (dial.scale) {
    case 'celsius':
      return '°C'
    case 'kph':
      return 'km/h'
    case 'raw':
      return undefined
    default:
      return '%'
  }
}

/**
 * The bounds a slider gets, in the unit it displays.
 *
 * Derived from what the game reported, never from a table: `min` and
 * `max` come off the reading.
 */
export function displayBounds(
  dial: ClimateDial,
  value: ClimateValue,
  maxWindKph: number,
): { min: number; max: number } {
  return {
    min: toDisplay(dial, value.min, maxWindKph, value.max),
    max: toDisplay(dial, value.max, maxWindKph, value.max),
  }
}

/** Sets one value, through the action that owns it. */
export function setDial(
  serverId: string,
  dial: ClimateDial,
  shown: number,
): Promise<unknown> {
  if (dial.action === undefined) {
    return Promise.reject(new Error(`${dial.name} has no action`))
  }

  // The actions take what the panel displays -- percent, °C, km/h -- and
  // EventController::climateValue() is the single place it becomes a
  // 0..1 climate value.
  return triggerEvent(serverId, dial.action, { value: shown })
}
