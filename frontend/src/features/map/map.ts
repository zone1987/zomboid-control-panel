import { apiFetch } from '@/lib/api'

export type MapStatus = {
  available: boolean
  tileSize: number
  maxLevel: number
  world: { width: number; height: number }
  levels: { level: number; columns: number; rows: number }[]
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

export type MapOverlay = {
  players: MapPlayer[]
  safehouses: MapSafehouse[]
  error: string | null
}

export function mapStatus(): Promise<MapStatus> {
  return apiFetch('/map')
}

export function mapOverlay(serverId: string): Promise<MapOverlay> {
  return apiFetch(`/map/${serverId}/overlay`)
}

/** Which tiles the panel has, and what shape they are. */
export type MapSources = {
  /** The game's own top-down map, from pyramid.zip. */
  gameMap: { available: boolean; tileSize: number; maxLevel: number }
  /** An isometric render, when the operator has generated one. */
  isometric: IsometricSource | null
}

export type IsometricSource = {
  available: boolean
  /** Floors the render covers. */
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
  }
}

export function mapSources(): Promise<MapSources> {
  return apiFetch('/map/sources')
}

export function parseCoordinates(needle: string): { x: number; y: number } | null {
  const match = needle.trim().match(/^(\d{1,5})\s*[,x\s]\s*(\d{1,5})$/)

  if (match === null) {
    return null
  }

  return { x: Number.parseInt(match[1], 10), y: Number.parseInt(match[2], 10) }
}
