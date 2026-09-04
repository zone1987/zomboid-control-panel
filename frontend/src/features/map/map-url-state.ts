/**
 * The part of the view that belongs in a link.
 *
 * Held in the URL hash rather than the query string: it changes on every
 * pan, and a hash change does not reach the router or the server.
 */
export type MapViewState = {
  x: number
  y: number
  zoom: number
  floor: number
}

/**
 * `#x,y,zoom,floor` -- four numbers, in the order somebody would read
 * them out. The floor is left off when it is the ground, which is what
 * most links point at.
 */
export function encodeViewState(state: MapViewState): string {
  const parts = [
    Math.round(state.x),
    Math.round(state.y),
    Number(state.zoom.toFixed(2)),
  ]

  if (state.floor !== 0) {
    parts.push(state.floor)
  }

  return parts.join(',')
}

export function decodeViewState(hash: string): MapViewState | null {
  const cleaned = hash.replace(/^#/, '').trim()

  if (cleaned === '') {
    return null
  }

  // Also accepts the "12574x4415x44" form other Zomboid maps use, so a
  // link from one of those lands somewhere sensible.
  const parts = cleaned.split(/[,x]/).map((part) => Number.parseFloat(part))

  if (parts.length < 2 || parts.some((part) => !Number.isFinite(part))) {
    return null
  }

  return {
    x: parts[0],
    y: parts[1],
    zoom: parts[2] ?? 1,
    floor: Math.trunc(parts[3] ?? 0),
  }
}

/** True when the two describe the same view, so the URL is left alone. */
export function isSameView(a: MapViewState | null, b: MapViewState): boolean {
  if (a === null) {
    return false
  }

  return (
    Math.round(a.x) === Math.round(b.x) &&
    Math.round(a.y) === Math.round(b.y) &&
    Math.abs(a.zoom - b.zoom) < 0.01 &&
    a.floor === b.floor
  )
}

/**
 * The hash as it was when the page was opened.
 *
 * Captured at module load, which is before any component renders and
 * well before the viewer starts writing its own position back. Reading
 * it later loses the deep link to a race with that writer -- which is
 * exactly what happened once.
 */
const ARRIVED_WITH = typeof window === 'undefined' ? '' : window.location.hash

export function viewStateOnArrival(): MapViewState | null {
  return decodeViewState(ARRIVED_WITH)
}
