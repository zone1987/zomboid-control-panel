import { apiFetch } from '@/lib/api'

export type EventFieldType = 'number' | 'player' | 'text' | 'choice' | 'toggle'

export type EventField = {
  name: string
  type: EventFieldType
  required?: boolean
  min?: number
  max?: number
  default?: number | string | boolean
  choices?: string[]
  maxLength?: number
}

/**
 * The five categories the backend sorts actions into. Mirrored from
 * `EventAction::CATEGORIES` and asserted against it by a test, because
 * this list decides an icon, a route and a label.
 */
export const EVENT_CATEGORIES = ['weather', 'sounds', 'actions', 'zombies', 'world'] as const

export type EventCategory = (typeof EVENT_CATEGORIES)[number]

export type EventAction = {
  id: string
  category: EventCategory
  channel: 'rcon' | 'bridge' | 'preferred'
  commands: string[]
  destructive: boolean
  fields: EventField[]
  available: boolean
  missing: string[]
}

export type EventCatalogue = {
  items: EventAction[]
  commandsKnown: boolean
  error: string | null
}

export type EventResult = {
  status: string
  action: string
  command: string
  reply: string
  failed: boolean
}

export type RecentEvent = {
  action: string
  command: string | null
  reply: string | null
  performedBy: string | null
  performedAt: string
}

export function listEvents(serverId: string): Promise<EventCatalogue> {
  return apiFetch(`/servers/${serverId}/events`)
}

export function triggerEvent(
  serverId: string,
  actionId: string,
  inputs: Record<string, string | number | boolean>,
): Promise<EventResult> {
  return apiFetch(`/servers/${serverId}/events/${actionId}`, { method: 'POST', body: inputs })
}

export function listRecentEvents(serverId: string): Promise<{ items: RecentEvent[] }> {
  return apiFetch(`/servers/${serverId}/events/recent/actions`)
}

export function defaultsFor(action: EventAction): Record<string, string | number | boolean> {
  const values: Record<string, string | number | boolean> = {}

  for (const field of action.fields) {
    if (field.default !== undefined) {
      values[field.name] = field.default
    } else if (field.type === 'number') {
      values[field.name] = field.min ?? 0
    } else {
      values[field.name] = ''
    }
  }

  return values
}

/**
 * Whether every required input has a usable value. Any number that carries
 * a value is checked against the range the action declared, so an
 * impossible request never reaches the server.
 */
export function isComplete(
  action: EventAction,
  values: Record<string, string | number | boolean>,
): boolean {
  return action.fields.every((field) => {
    const value = values[field.name]

    if (field.required === false && String(value ?? '').trim() === '') {
      return true
    }

    if (field.type === 'number') {
      const parsed = typeof value === 'number' ? value : Number.parseInt(String(value ?? ''), 10)

      return (
        Number.isFinite(parsed) &&
        (field.min === undefined || parsed >= field.min) &&
        (field.max === undefined || parsed <= field.max)
      )
    }

    if (field.required === false) {
      return true
    }

    return String(value ?? '').trim() !== ''
  })
}
