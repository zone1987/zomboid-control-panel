/**
 * The weather stages the game can be told to run.
 *
 * Mirrored from `BridgeCommand::WEATHER_STAGES`, and asserted against it
 * by a test — the bridge refuses anything else, so a name that drifts
 * here would offer a control that always fails.
 */
export const WEATHER_STAGES = [
  'showers',
  'heavyPrecip',
  'storm',
  'clearing',
  'moderate',
  'drizzle',
  'blizzard',
  'tropical',
] as const

export type WeatherStage = (typeof WEATHER_STAGES)[number]
