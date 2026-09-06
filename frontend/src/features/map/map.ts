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

export type VehicleCondition = {
  front: number
  frontMax: number
  rear: number
  rearMax: number
  damaged: boolean
  wrecked: boolean
  /** Share of total durability still intact, 0 to 1. */
  intact: number
}

export type MapVehicle = {
  id: number
  /** The game's own script name, e.g. "Base.PickUpVan". */
  script: string
  x: number
  y: number
  z: number
  /** Degrees clockwise from north; null when the rotation was unreadable. */
  heading: number | null
  /** Which texture variant the vehicle's script declares. */
  skin: number | null
  engineRunning: boolean
  fuel?: number | null
  /** Paint, as the game stores it. Only vehicles the bridge can see have it. */
  hue?: number | null
  saturation?: number | null
  value?: number | null
  rust?: number | null
  condition?: VehicleCondition
  /** True when the bridge could read this one from the running world. */
  live?: boolean
}

export type MapFaction = {
  name: string
  owner: string
  tag: string
  members: string[]
}

/**
 * Where the vehicle list came from.
 *
 * "saved" is the server's own database, which holds every vehicle the
 * world has generated; "bridge" only the ones in loaded chunks; "both"
 * means the database was filled in with live paint and positions.
 */
export type VehicleSource = 'saved' | 'bridge' | 'both' | 'none'

export type MapOverlay = {
  players: MapPlayer[]
  safehouses: MapSafehouse[]
  /** Every vehicle the world has, not only the loaded ones. */
  vehicles: MapVehicle[]
  vehicleSource?: VehicleSource
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
