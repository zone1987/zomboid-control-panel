import {
  Cloudy,
  CloudDrizzle,
  CloudFog,
  CloudLightning,
  CloudRain,
  CloudRainWind,
  Sun,
  Wind,
  type LucideIcon,
} from 'lucide-react'

/** One action of a preset, with the inputs it is fired with. */
export type PresetStep = {
  action: string
  inputs?: Record<string, number>
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
  steps: PresetStep[]
}

/**
 * Ordered from clear to worst, so the row reads as a scale.
 *
 * The intensities are the game's own 0..100 except the wind, which is
 * km/h against the game's own 120 ceiling. The fog, cloud and wind steps
 * go through the bridge — a preset including them does more on a server
 * with the bridge installed and still does its rain without one.
 */
export const WEATHER_PRESETS: WeatherPreset[] = [
  {
    id: 'clear',
    icon: Sun,
    steps: [
      { action: 'stopWeather' },
      { action: 'setClouds', inputs: { value: 0 } },
      // stopWeather ends the precipitation and leaves the wind where the
      // last downpour put it, which is not what "clear" means.
      { action: 'setWind', inputs: { value: 0 } },
      { action: 'setFog', inputs: { value: 0 } },
    ],
  },
  {
    id: 'cloudy',
    icon: Cloudy,
    steps: [{ action: 'stopRain' }, { action: 'setClouds', inputs: { value: 70 } }],
  },
  {
    id: 'drizzle',
    icon: CloudDrizzle,
    steps: [
      { action: 'startRain', inputs: { intensity: 20 } },
      { action: 'setClouds', inputs: { value: 50 } },
    ],
  },
  {
    id: 'rain',
    icon: CloudRain,
    steps: [
      { action: 'startRain', inputs: { intensity: 60 } },
      { action: 'setClouds', inputs: { value: 80 } },
    ],
  },
  {
    id: 'downpour',
    icon: CloudRainWind,
    steps: [
      { action: 'startRain', inputs: { intensity: 100 } },
      { action: 'setClouds', inputs: { value: 100 } },
      { action: 'setWind', inputs: { value: 85 } },
    ],
  },
  {
    id: 'thunderstorm',
    icon: CloudLightning,
    steps: [{ action: 'startStorm', inputs: { duration: 3 } }],
  },
  {
    id: 'fog',
    icon: CloudFog,
    steps: [{ action: 'setFog', inputs: { value: 80 } }],
  },
  {
    id: 'wind',
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
