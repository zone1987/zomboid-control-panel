import { apiFetch } from '@/lib/api'

/**
 * What a connection can be.
 *
 * `unconfigured` is deliberately not `down`: nothing is broken, it was
 * never set up, and a light that cannot tell those apart sends somebody
 * hunting for a fault. `stale` is for a bridge that works but is behind.
 */
export type ConnectionState = 'up' | 'down' | 'stale' | 'unconfigured' | 'unknown'

export type Connection = {
  state: ConnectionState
  /** A translation key naming the reason, when there is one. */
  detail: string | null
}

export type ConnectionStatus = {
  ftp: Connection
  rcon: Connection
  bridge: Connection & { version: string | null }
  game: Connection & { secondsAgo: number | null }
}

/** The order the lights are shown in: outermost reach first. */
export const CONNECTION_ORDER = ['game', 'rcon', 'ftp', 'bridge'] as const

export type ConnectionName = (typeof CONNECTION_ORDER)[number]

export function getConnections(serverId: string): Promise<ConnectionStatus> {
  return apiFetch<ConnectionStatus>(`/servers/${serverId}/connections`)
}

/** Whether anything is actually wrong, for the collapsed mobile summary. */
export function worstOf(status: ConnectionStatus): ConnectionState {
  const states = CONNECTION_ORDER.map((name) => status[name].state)

  if (states.includes('down')) {
    return 'down'
  }

  if (states.includes('stale') || states.includes('unknown')) {
    return 'stale'
  }

  return states.every((state) => state === 'unconfigured') ? 'unconfigured' : 'up'
}

/**
 * Where a light sends you when clicked.
 *
 * A red light is a question — "which leg is broken?" — and the answer
 * is always on the same page, so the light is the way there rather than
 * something to read and then navigate from.
 *
 * The game itself has no settings of its own: it is up or it is not, and
 * what an operator would check is the bridge's own reading.
 */
export function settingsFor(name: ConnectionName, serverId: string): string {
  return {
    ftp: `/servers/${serverId}?tab=ftp`,
    rcon: `/servers/${serverId}?tab=rcon`,
    bridge: `/servers/${serverId}#bridge`,
    game: `/servers/${serverId}#bridge`,
  }[name]
}
