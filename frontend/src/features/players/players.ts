import { apiFetch } from '@/lib/api'

export const ACCESS_LEVELS = ['admin', 'moderator', 'overseer', 'gm', 'observer', 'none'] as const

export type AccessLevel = (typeof ACCESS_LEVELS)[number]

export type Player = {
  username: string
  steamId: string | null
  online: boolean
  position: { x: number | null; y: number | null; z: number | null }
  health: number | null
  infected: boolean
  infectionLevel: number | null
  hoursSurvived: number | null
  accessLevel: string | null
  skills: Record<string, number> | null
  traits: string[] | null
  lastSeenAt: string
  firstSeenAt: string
}

export type BridgeStatus = {
  playerCount: number
  generatedAt: string
  version: string | null
  expectedVersion: string
  stale: boolean
}

export type PlayerList = {
  items: Player[]
  bridge: BridgeStatus | null
  error: string | null
}

export type Ban = {
  username: string
  reason: string | null
  bannedAt: string
  expiresAt: string | null
  permanent: boolean
}

export type ModerationEntry = {
  action: string
  username: string
  reason: string | null
  performedBy: string | null
  performedAt: string
  expiresAt: string | null
}

export function listPlayers(serverId: string, onlineOnly = false): Promise<PlayerList> {
  const query = new URLSearchParams({ onlineOnly: String(onlineOnly) })

  return apiFetch<PlayerList>(`/servers/${serverId}/players?${query}`)
}

export function listBans(serverId: string): Promise<{ items: Ban[]; note: string }> {
  return apiFetch(`/servers/${serverId}/players/bans`)
}

export function listHistory(serverId: string): Promise<{ items: ModerationEntry[] }> {
  return apiFetch(`/servers/${serverId}/players/history`)
}

export function kickPlayer(serverId: string, username: string, reason?: string) {
  return apiFetch<{ status: string; reply: string }>(
    `/servers/${serverId}/players/${encodeURIComponent(username)}/kick`,
    { method: 'POST', body: { reason } },
  )
}

export function banPlayer(
  serverId: string,
  username: string,
  options: { reason?: string; durationMinutes?: number | null; includeIp?: boolean },
) {
  return apiFetch<{ status: string; reply: string }>(
    `/servers/${serverId}/players/${encodeURIComponent(username)}/ban`,
    { method: 'POST', body: options },
  )
}

export function unbanPlayer(serverId: string, username: string) {
  return apiFetch<{ status: string; reply: string }>(
    `/servers/${serverId}/players/${encodeURIComponent(username)}/unban`,
    { method: 'POST', body: {} },
  )
}

export type TeleportDestination = { target: string } | { x: number; y: number; z: number }

export function teleportPlayer(
  serverId: string,
  username: string,
  destination: TeleportDestination,
) {
  return apiFetch<{ status: string; reply: string }>(
    `/servers/${serverId}/players/${encodeURIComponent(username)}/teleport`,
    { method: 'POST', body: destination },
  )
}

/** Knox Country spans roughly this square; z is basement to top floor. */
export const WORLD_BOUNDS = { min: 0, max: 20000, minZ: -1, maxZ: 7 } as const

export function isInsideWorld(x: number, y: number, z: number): boolean {
  return (
    Number.isInteger(x) &&
    Number.isInteger(y) &&
    Number.isInteger(z) &&
    x >= WORLD_BOUNDS.min &&
    x <= WORLD_BOUNDS.max &&
    y >= WORLD_BOUNDS.min &&
    y <= WORLD_BOUNDS.max &&
    z >= WORLD_BOUNDS.minZ &&
    z <= WORLD_BOUNDS.maxZ
  )
}

/** Offered as hints in the teleport dialog, not as a complete gazetteer. */
export const LANDMARKS = [
  { id: 'muldraugh', x: 10778, y: 9770 },
  { id: 'westPoint', x: 11800, y: 6900 },
  { id: 'riverside', x: 6500, y: 5300 },
  { id: 'rosewood', x: 8000, y: 11800 },
  { id: 'marchRidge', x: 10100, y: 12800 },
  { id: 'louisville', x: 12800, y: 2000 },
] as const

export function setAccessLevel(serverId: string, username: string, level: AccessLevel) {
  return apiFetch<{ status: string; reply: string }>(
    `/servers/${serverId}/players/${encodeURIComponent(username)}/access-level`,
    { method: 'POST', body: { level } },
  )
}

/** Durations offered in the ban dialog, in minutes. */
/**
 * The abilities the server exposes, and the third state that matters.
 *
 * `unknown` is not a nicety: the server keeps none of these where the
 * panel can read them back, so a switch showing "off" would be claiming
 * something nobody checked. Two admins fighting over one flag is the
 * failure that avoids.
 */
export const ABILITIES = ['god', 'invisible', 'noclip', 'voiceBan'] as const

export type Ability = (typeof ABILITIES)[number]

export type AbilityState = 'on' | 'off' | 'unknown'

export function setAbility(serverId: string, username: string, ability: Ability, on: boolean) {
  return apiFetch<{ status: string; reply: string }>(
    `/servers/${serverId}/players/${encodeURIComponent(username)}/ability`,
    { method: 'POST', body: { ability, on } },
  )
}

/** As much as one grant may give, mirroring PlayerModerator::MAX_XP. */
export const MAX_XP = 100000

export function grantExperience(
  serverId: string,
  username: string,
  perk: string,
  amount: number,
  withMultiplier = false,
) {
  return apiFetch<{ status: string; reply: string }>(
    `/servers/${serverId}/players/${encodeURIComponent(username)}/experience`,
    { method: 'POST', body: { perk, amount, withMultiplier } },
  )
}

export type PlayerNote = {
  note: string | null
  tags: string[]
  updatedBy: string | null
  updatedAt: string | null
  /** The fixed suggestions, and what this server already uses. */
  suggested: string[]
  inUse: string[]
  limits: { note: number; tags: number; tagLength: number }
}

export function readNote(serverId: string, username: string): Promise<PlayerNote> {
  return apiFetch(`/servers/${serverId}/players/${encodeURIComponent(username)}/note`)
}

export function saveNote(serverId: string, username: string, note: string | null, tags: string[]) {
  return apiFetch<{ status: string; note: string | null; tags: string[] }>(
    `/servers/${serverId}/players/${encodeURIComponent(username)}/note`,
    { method: 'PUT', body: { note, tags } },
  )
}

export type PlayerHistoryEntry = {
  action: string
  reason: string | null
  reply: string | null
  performedBy: string | null
  performedAt: string
  /** What was asked for, so the list can say "rain at 70". */
  inputs: Record<string, string | number | boolean> | null
  /** True when refused, false when accepted, null when nobody checked. */
  failed: boolean | null
}

/** What was done to one player, and only to them. */
export function readPlayerHistory(serverId: string, username: string) {
  return apiFetch<{ items: PlayerHistoryEntry[] }>(
    `/servers/${serverId}/players/${encodeURIComponent(username)}/history`,
  )
}

/**
 * One character statistic, with the bounds the game itself declares.
 *
 * Measured on a live server, the twenty-four run 0..1, 0..100, −1..1,
 * 20..40 and even 0..0.51 — so the panel never assumes a range, and a
 * slider takes its ends from here.
 */
export type CharacterStat = {
  value: number
  min: number
  max: number
  default: number
}

export type PlayerVitals = {
  status: string
  player: string
  stats: Record<string, CharacterStat>
  weight: number | null
  profession: string | null
}

export function readVitals(serverId: string, username: string): Promise<PlayerVitals> {
  return apiFetch(`/servers/${serverId}/players/${encodeURIComponent(username)}/vitals`)
}

export function setStat(serverId: string, username: string, stat: string, value: number) {
  return apiFetch<{ status: string; reply: string }>(
    `/servers/${serverId}/players/${encodeURIComponent(username)}/vitals`,
    { method: 'POST', body: { stat, value } },
  )
}

export function setWeight(serverId: string, username: string, weight: number) {
  return apiFetch<{ status: string; reply: string }>(
    `/servers/${serverId}/players/${encodeURIComponent(username)}/weight`,
    { method: 'POST', body: { weight } },
  )
}

export function healPlayer(serverId: string, username: string) {
  return apiFetch<{ status: string; reply: string; parts?: number }>(
    `/servers/${serverId}/players/${encodeURIComponent(username)}/heal`,
    { method: 'POST' },
  )
}

/**
 * The statistics worth putting in front of somebody, in this order.
 *
 * All twenty-four are readable, but a dossier is for answering "why is
 * this player complaining" — so the ones a person acts on come first and
 * the rest sit behind a disclosure. Verified against the live server's
 * own registry.
 */
export const PRIMARY_STATS = [
  'Hunger',
  'Thirst',
  'Fatigue',
  'Endurance',
  'Pain',
  'Panic',
  'Stress',
  'Sickness',
  'ZombieInfection',
] as const

export const BAN_DURATIONS = [
  { id: '1h', minutes: 60 },
  { id: '2h', minutes: 120 },
  { id: '6h', minutes: 360 },
  { id: '12h', minutes: 720 },
  { id: '1d', minutes: 1440 },
  { id: '2d', minutes: 2880 },
  { id: '5d', minutes: 7200 },
  { id: '1w', minutes: 10080 },
  { id: '30d', minutes: 43200 },
  { id: 'permanent', minutes: null },
] as const
