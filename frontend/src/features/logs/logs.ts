import { apiFetch } from '@/lib/api'

export type LogFile = {
  kind: string
  path: string
  name: string
  size: number | null
  lastModified: number | null
}

export type LogChunk = {
  lines: string[]
  offset: number
  truncated: boolean
  rotated: boolean
}

export function listLogs(serverId: string): Promise<{ items: LogFile[]; kinds: string[] }> {
  return apiFetch(`/servers/${serverId}/logs`)
}

export function tailLog(serverId: string, path: string, offset: number | null): Promise<LogChunk> {
  const query = new URLSearchParams({ path })

  if (offset !== null) {
    query.set('offset', String(offset))
  }

  return apiFetch<LogChunk>(`/servers/${serverId}/logs/tail?${query}`)
}

export type LogLine = {
  id: number
  timestamp: string | null
  body: string
  level: 'info' | 'warn' | 'error' | null
}

/**
 * Zomboid writes two shapes: "[04-09-26 18:28:08.836][info] text" in the
 * chat and debug logs, and "[04-09-26 18:29:33.422] event=..." in the
 * connection log. Both start with a bracketed stamp, which is the part
 * worth separating so the rest can be read at a glance.
 */
export function parseLine(line: string, id: number): LogLine {
  const match = /^\[([^\]]+)\](?:\[(\w+)\])?\s*(.*)$/.exec(line)

  if (match === null) {
    return { id, timestamp: null, body: line, level: null }
  }

  const [, timestamp, rawLevel, body] = match
  const level = rawLevel?.toLowerCase()

  return {
    id,
    timestamp,
    body,
    level:
      level === 'error' || level === 'severe'
        ? 'error'
        : level === 'warn' || level === 'warning'
          ? 'warn'
          : level === 'info' || level === 'debug'
            ? 'info'
            : null,
  }
}
