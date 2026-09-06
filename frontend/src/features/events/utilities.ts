import { apiFetch } from '@/lib/api'

/** One utility: whether it runs, and until when. */
export type Utility = {
  on: boolean
  /**
   * The day of the apocalypse it stops on. `-1` is the game's own
   * "never"; null when the bridge could not read it.
   */
  shutAt: number | null
  /** Days left, where that is a countdown at all. */
  daysLeft: number | null
}

export type UtilityReading = {
  status: string
  /** How many days into the apocalypse the world is. */
  day: number
  power: Utility
  water: Utility
}

/** The game's own "never shuts off". */
export const NEVER = -1

export function readUtilities(serverId: string): Promise<UtilityReading> {
  return apiFetch(`/servers/${serverId}/utilities`)
}
