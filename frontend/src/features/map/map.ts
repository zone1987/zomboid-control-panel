import { apiFetch } from '@/lib/api'

export type MapStatus = {
  available: boolean
  tileSize: number
  maxLevel: number
  world: { width: number; height: number }
  levels: { level: number; columns: number; rows: number }[]
  isometric: IsometricSource
}

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

export function mapStatus(): Promise<MapStatus> {
  return apiFetch('/map')
}

export function mapOverlay(serverId: string): Promise<MapOverlay> {
  return apiFetch(`/map/${serverId}/overlay`)
}

/**
 * An isometric render, when the operator has made one.
 *
 * The geometry is read from the render's own map_info.json rather than
 * assumed: it changes with the cell range and the pyramid levels the
 * operator chose, and guessing puts every marker somewhere else.
 */
export type IsometricSource = {
  available: boolean
  /** Floors the render actually covers, from the files it contains. */
  levels: number[]
  geometry: {
    originX: number
    originY: number
    squareSize: number
    scale: number
    floorHeight: number
    width: number
    height: number
    cellSize: number
  } | null
}

export function parseCoordinates(needle: string): { x: number; y: number } | null {
  const match = needle.trim().match(/^(\d{1,5})\s*[,x\s]\s*(\d{1,5})$/)

  if (match === null) {
    return null
  }

  return { x: Number.parseInt(match[1], 10), y: Number.parseInt(match[2], 10) }
}
