import { ApiError, apiFetch } from '@/lib/api'

/** How much of the game's chat may leave the game. */
export type ChatScope = 'general' | 'noShouting' | 'allPublic'

export type DiscordRole = {
  id: string
  name: string
  /** Discord's own colour as an integer; 0 means "no colour set". */
  colour: number
  position: number
  /** Managed by an integration, so it cannot be assigned by hand. */
  managed: boolean
}

export type DiscordChannel = {
  id: string
  name: string
  type: number
  parent: string | null
}

export type DiscordEventSetting = {
  type: string
  /** True for the sixteen that are about what a person did. */
  adminAction: boolean
  enabled: boolean
  /** Enabled *and* pointed at a channel; only then does anything happen. */
  active: boolean
  channelId: string | null
  template: string | null
  defaultTemplate: string | null
  /** Which `{tokens}` this event carries, for checking the wording. */
  tokens: string[]
}

export type DiscordCommandSetting = {
  name: string
  command: string
  subcommand: string
  /** What the same act costs in the panel. Null would mean ungated. */
  permission: string | null
  roleIds: string[]
}

export type DiscordSetup = {
  tokenConfigured: boolean
  applicationId: string
  publicKeyConfigured: boolean
  guildId: string
  chatChannelId: string | null
  chatScope: ChatScope
  relayIntoGame: boolean
  commandsEnabled: boolean
  events: DiscordEventSetting[]
  commands: DiscordCommandSetting[]
}

export function getDiscordSetup(serverId: string): Promise<DiscordSetup> {
  return apiFetch(`/servers/${serverId}/discord`)
}

export function getDiscordChannels(serverId: string): Promise<{ channels: DiscordChannel[] }> {
  return apiFetch(`/servers/${serverId}/discord/channels`)
}

export function getDiscordRoles(serverId: string): Promise<{ roles: DiscordRole[] }> {
  return apiFetch(`/servers/${serverId}/discord/roles`)
}

/** Discord's integer colour as CSS, or null where none is set. */
export function roleColour(role: DiscordRole): string | null {
  return role.colour === 0 ? null : `#${role.colour.toString(16).padStart(6, '0')}`
}

export function updateDiscordSetup(
  serverId: string,
  changes: Partial<Pick<DiscordSetup, 'guildId' | 'chatChannelId' | 'chatScope' | 'relayIntoGame' | 'commandsEnabled'>>,
): Promise<{ status: string }> {
  return apiFetch(`/servers/${serverId}/discord`, { method: 'PATCH', body: changes })
}

export function saveDiscordEvent(
  serverId: string,
  type: string,
  changes: { enabled?: boolean; channelId?: string | null; template?: string | null },
): Promise<{ status: string; unknownTokens: string[] }> {
  return apiFetch(`/servers/${serverId}/discord/events/${type}`, { method: 'PUT', body: changes })
}

export function testDiscordEvent(
  serverId: string,
  type: string,
  body: { channelId?: string | null; template?: string | null },
): Promise<{ status: string; content: string }> {
  return apiFetch(`/servers/${serverId}/discord/events/${type}/test`, { method: 'POST', body })
}

export function saveDiscordCommand(
  serverId: string,
  command: string,
  roleIds: string[],
): Promise<{ status: string; roleIds: string[] }> {
  return apiFetch(`/servers/${serverId}/discord/commands/${command}`, {
    method: 'PUT',
    body: { roleIds },
  })
}

export function registerDiscordCommands(
  serverId: string,
): Promise<{ status: string; count: number }> {
  return apiFetch(`/servers/${serverId}/discord/register`, { method: 'POST', body: {} })
}

/**
 * Whether the panel could talk to Discord at all.
 *
 * Three separate things, and saying which one is missing is the
 * difference between a page somebody can fix and one they can only
 * stare at.
 */
export function setupGaps(setup: DiscordSetup): string[] {
  const missing: string[] = []

  if (!setup.tokenConfigured) {
    missing.push('token')
  }

  if (setup.applicationId === '') {
    missing.push('applicationId')
  }

  if (!setup.publicKeyConfigured) {
    missing.push('publicKey')
  }

  if (setup.guildId === '') {
    missing.push('guild')
  }

  return missing
}

/**
 * How many events would actually send something.
 *
 * Counted on `active` rather than `enabled`: a switch turned on with no
 * channel behind it sends nothing, and counting it would tell the
 * operator their notifications work when they do not.
 */
export function activeEventCount(events: DiscordEventSetting[]): number {
  return events.filter((event) => event.active).length
}

/** A command nobody can run, so the operator can see the gap. */
export function ungrantedCommands(commands: DiscordCommandSetting[]): DiscordCommandSetting[] {
  return commands.filter((command) => command.roleIds.length === 0)
}

/**
 * Why a Discord request failed, in the operator's language.
 *
 * The backend answers with a message key rather than an HTTP status,
 * because "Discord rejected the token" and "Discord is rate limiting"
 * send somebody to two different places.
 */
export function reasonFor(error: unknown, t: (key: string) => string): string {
  if (!(error instanceof ApiError)) {
    return t('discord.unreachable')
  }

  const body = error.payload as { error?: string } | undefined

  return body?.error === undefined ? t('discord.refused') : t(body.error)
}
