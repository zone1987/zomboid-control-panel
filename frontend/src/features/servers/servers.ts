import { apiFetch } from '@/lib/api'

export type FtpSettings = {
  protocol: 'ftp' | 'sftp'
  host: string
  port: number
  username: string
  hasPassword: boolean
  hasPrivateKey: boolean
  basePath: string
  luaServerPath: string | null
  logPath: string | null
  lastVerifiedAt: string | null
}

export type RconSettings = {
  host: string
  port: number
  hasPassword: boolean
  lastVerifiedAt: string | null
}

export type GameServer = {
  id: string
  name: string
  description: string | null
  createdAt: string
  ftp: FtpSettings | null
  rcon: RconSettings | null
}

export type DirectoryEntry = {
  name: string
  path: string
  type: 'directory' | 'file'
  size: number | null
  lastModified: number | null
}

export type DirectoryListing = {
  path: string
  entries: DirectoryEntry[]
}

export type ServerDraft = {
  name?: string
  description?: string | null
  ftp?: Partial<{
    protocol: string
    host: string
    port: number
    username: string
    password: string
    privateKey: string
    basePath: string
    luaServerPath: string
    logPath: string
  }>
  rcon?: Partial<{
    host: string
    port: number
    password: string
  }>
}

export function listServers(): Promise<{ items: GameServer[] }> {
  return apiFetch<{ items: GameServer[] }>('/servers')
}

export function getServer(id: string): Promise<GameServer> {
  return apiFetch<GameServer>(`/servers/${id}`)
}

export function createServer(name: string, description?: string): Promise<GameServer> {
  return apiFetch<GameServer>('/servers', { method: 'POST', body: { name, description } })
}

export function updateServer(id: string, draft: ServerDraft): Promise<GameServer> {
  return apiFetch<GameServer>(`/servers/${id}`, { method: 'PATCH', body: draft })
}

export function deleteServer(id: string): Promise<void> {
  return apiFetch<void>(`/servers/${id}`, { method: 'DELETE' })
}

export function testFtp(id: string): Promise<{ status: string; path: string; entryCount: number; looksLikeZomboid: boolean }> {
  return apiFetch(`/servers/${id}/ftp/test`, { method: 'POST', body: {} })
}

export function testRcon(id: string): Promise<{ status: string; reply: string }> {
  return apiFetch(`/servers/${id}/rcon/test`, { method: 'POST', body: {} })
}

export function browse(id: string, path = ''): Promise<DirectoryListing> {
  return apiFetch<DirectoryListing>(`/servers/${id}/files?path=${encodeURIComponent(path)}`)
}
