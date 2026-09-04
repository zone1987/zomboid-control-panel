import { apiFetch } from '@/lib/api'

export type GameTime = {
  year: number
  month: number
  day: number
  hour: number
  minute: number
  daysSurvived: number
}

export type Weather = {
  temperature: number
  raining: boolean
  snowing: boolean
  windSpeed: number
  season: string
}

export type WorldState = {
  generatedAt: number | null
  gameTime: GameTime | null
  weather: Weather | null
  maxPlayers: number | null
} | null

export type Safehouse = {
  title: string
  owner: string
  x: number
  y: number
  w: number
  h: number
  members: string[]
}

export function getWorld(serverId: string): Promise<WorldState> {
  return apiFetch<WorldState>(`/servers/${serverId}/world`)
}

export function listSafehouses(
  serverId: string,
): Promise<{ items: Safehouse[]; available: boolean }> {
  return apiFetch(`/servers/${serverId}/safehouses`)
}
