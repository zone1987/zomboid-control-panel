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

export const TILE_URL = '/api/map/tiles/{z}/{x}/{y}.png'

/**
 * Level 0 of the game's own pyramid is one pixel per world square, so
 * world coordinates and image pixels are the same number. Leaflet counts
 * zoom the other way round -- 0 is furthest out -- hence the flip.
 */
export function leafletZoomOf(level: number, maxLevel: number): number {
  return maxLevel - level
}

export function levelOfLeafletZoom(zoom: number, maxLevel: number): number {
  return maxLevel - zoom
}

/** Places worth jumping to, the same list the teleport dialog offers. */
export const PLACES = [
  { id: 'muldraugh', x: 10778, y: 9770 },
  { id: 'westPoint', x: 11800, y: 6900 },
  { id: 'riverside', x: 6500, y: 5300 },
  { id: 'rosewood', x: 8000, y: 11800 },
  { id: 'marchRidge', x: 10100, y: 12800 },
  { id: 'louisville', x: 12800, y: 2000 },
] as const

export function parseCoordinates(needle: string): { x: number; y: number } | null {
  const match = needle.trim().match(/^(\d{1,5})\s*[,x\s]\s*(\d{1,5})$/)

  if (match === null) {
    return null
  }

  return { x: Number.parseInt(match[1], 10), y: Number.parseInt(match[2], 10) }
}
