import { apiFetch } from '@/lib/api'

export type ChatKind = 'message' | 'broadcast' | 'system' | 'ignore'

export type ChatLine = {
  kind: ChatKind
  timestamp: string | null
  author: string | null
  text: string
  raw: string
}

export type ChatChunk = {
  lines: ChatLine[]
  offset: number | null
  file: string | null
  rotated?: boolean
  error: string | null
}

export function readChat(
  serverId: string,
  file: string | null,
  offset: number | null,
): Promise<ChatChunk> {
  const query = new URLSearchParams()

  if (file !== null && offset !== null) {
    query.set('file', file)
    query.set('offset', String(offset))
  }

  const suffix = query.toString() === '' ? '' : `?${query}`

  return apiFetch<ChatChunk>(`/servers/${serverId}/chat${suffix}`)
}

export function sendChat(
  serverId: string,
  message: string,
): Promise<{ status: string; message: string; reply: string }> {
  return apiFetch(`/servers/${serverId}/chat`, { method: 'POST', body: { message } })
}

/** Matches the server's own limit, so the field can stop before the trip. */
export const MAX_MESSAGE_LENGTH = 250
