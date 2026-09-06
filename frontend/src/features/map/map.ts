import { apiFetch } from '@/lib/api'

export type MapPlayer = {
  username: string
  x: number
  y: number
  z: number | null
  health: number | null
  infected: boolean
  accessLevel: string | null
}

export type MapSafehouse = {
  title: string
  owner: string
  x: number
  y: number
  w: number
  h: number
  members: string[]
}

export type MapVehicle = {
  id: number
  script: string
  x: number
  y: number
  z: number
  fuel: number | null
  engineRunning: boolean
}

export type MapFaction = {
  name: string
  owner: string
  tag: string
  members: string[]
}

export type MapOverlay = {
  players: MapPlayer[]
  safehouses: MapSafehouse[]
  /** Only the loaded ones: a vehicle in an unloaded chunk is not there. */
  vehicles: MapVehicle[]
  factions: MapFaction[]
  error: string | null
}

export function mapOverlay(serverId: string): Promise<MapOverlay> {
  return apiFetch(`/map/${serverId}/overlay`)
}

export function parseCoordinates(needle: string): { x: number; y: number } | null {
  const match = needle.trim().match(/^(\d{1,5})\s*[,x\s]\s*(\d{1,5})$/)

  if (match === null) {
    return null
  }

  return { x: Number.parseInt(match[1], 10), y: Number.parseInt(match[2], 10) }
}
