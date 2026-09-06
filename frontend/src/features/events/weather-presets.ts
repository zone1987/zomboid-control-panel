import {
  Cloudy,
  CloudDrizzle,
  CloudFog,
  CloudLightning,
  CloudRain,
  CloudRainWind,
  CloudSnow,
  Snowflake,
  Sun,
  Wind,
  type LucideIcon,
} from 'lucide-react'

/** One action of a preset, with the inputs it is fired with. */
export type PresetStep = {
  action: string
  inputs?: Record<string, number | boolean>
  /**
   * A label key under `events.stepLabels`, where the action's own name
   * reads wrongly in this preset's context: "start rain" is what the
   * command is called, but on the snow preset what falls is snow.
   */
  label?: string
  /**
   * Bounds narrower than the action's own, where the preset only works
   * inside part of the range: a temperature slider on the snow preset
   * that reaches +40 offers the operator a way to undo the snow they
   * just asked for.
   */
  min?: number
  max?: number
}

/**
 * A named bundle of existing event actions.
 *
 * A frontend concept on purpose: every step is an action the catalogue
 * already declares and the endpoint already accepts, so a preset adds no
 * capability and cannot drift from the backend. What it adds is that
 * "Gewitter" is one click rather than four fields somebody has to know
 * how to fill.
 */
export type WeatherPreset = {
  id: string
  icon: LucideIcon
  group: PresetGroup
  steps: PresetStep[]
}

/**
 * The four kinds of weather, so ten tiles read as four short rows.
 *
 * Grouped by what somebody is reaching for rather than by intensity: a
 * flat row of ten icons makes finding "snow" a scan, and drizzle sitting
 * beside a blizzard implies a scale the two are not on.
 */
export const PRESET_GROUPS = ['calm', 'rain', 'snow', 'air'] as const

export type PresetGroup = (typeof PRESET_GROUPS)[number]

/** The presets of one group, in the order they are declared. */
export function presetsOf(group: PresetGroup): WeatherPreset[] {
  return WEATHER_PRESETS.filter((preset) => preset.group === group)
}

/**
 * Declared in group order, and within a group from mildest to worst.
 *
 * The intensities are the game's own 0..100 except the wind, which is
 * km/h against the game's own 120 ceiling. The fog, cloud and wind steps
 * go through the bridge — a preset including them does more on a server
 * with the bridge installed and still does its rain without one.
 */
export const WEATHER_PRESETS: WeatherPreset[] = [
  {
    id: 'clear',
    group: 'calm',
    icon: Sun,
    steps: [
      { action: 'stopWeather' },
      { action: 'setClouds', inputs: { value: 0 } },
      // stopWeather ends the precipitation and leaves the wind where the
      // last downpour put it, which is not what "clear" means.
      { action: 'setWind', inputs: { value: 0 } },
      { action: 'setFog', inputs: { value: 0 } },
      // Not a temperature of its own: the snow presets pinned one below
      // freezing, and only the game knows what July should be. Releasing
      // the pin hands the season back.
      { action: 'releaseTemperature' },
    ],
  },
  {
    id: 'cloudy',
    group: 'calm',
    icon: Cloudy,
    steps: [{ action: 'stopRain' }, { action: 'setClouds', inputs: { value: 70 } }],
  },
  {
    id: 'drizzle',
    group: 'rain',
    icon: CloudDrizzle,
    steps: [
      { action: 'startRain', inputs: { intensity: 20 } },
      { action: 'setClouds', inputs: { value: 50 } },
    ],
  },
  {
    id: 'rain',
    group: 'rain',
    icon: CloudRain,
    steps: [
      { action: 'startRain', inputs: { intensity: 60 } },
      { action: 'setClouds', inputs: { value: 80 } },
    ],
  },
  {
    id: 'downpour',
    group: 'rain',
    icon: CloudRainWind,
    steps: [
      { action: 'startRain', inputs: { intensity: 100 } },
      { action: 'setClouds', inputs: { value: 100 } },
      { action: 'setWind', inputs: { value: 85 } },
    ],
  },
  {
    id: 'thunderstorm',
    group: 'rain',
    icon: CloudLightning,
    steps: [{ action: 'startStorm', inputs: { duration: 3 } }],
  },
  {
    id: 'snow',
    group: 'snow',
    icon: Snowflake,
    steps: [
      // The cold first: the game refuses snow above freezing, so asking
      // for it before the temperature drops is asking to be refused.
      { action: 'setTemperature', inputs: { value: -5 }, max: -1 },
      // Then the type, then the fall: snow with nothing falling changes
      // nothing visible.
      { action: 'setSnow', inputs: { snowing: true } },
      { action: 'startRain', inputs: { intensity: 50 }, label: 'startSnow' },
      { action: 'setClouds', inputs: { value: 80 } },
    ],
  },
  {
    id: 'blizzard',
    group: 'snow',
    icon: CloudSnow,
    steps: [
      { action: 'setTemperature', inputs: { value: -12 }, max: -1 },
      // The game's own winter storm, which sets the precipitation, the
      // wind and the temperature together the way the season does.
      { action: 'startBlizzard' },
    ],
  },
  {
    id: 'fog',
    group: 'air',
    icon: CloudFog,
    steps: [{ action: 'setFog', inputs: { value: 80 } }],
  },
  {
    id: 'wind',
    group: 'air',
    icon: Wind,
    steps: [{ action: 'setWind', inputs: { value: 95 } }],
  },
]

/**
 * Which actions a preset needs, so one that cannot run is not offered as
 * though it could.
 */
export function actionsOf(preset: WeatherPreset): string[] {
  return preset.steps.map((step) => step.action)
}
