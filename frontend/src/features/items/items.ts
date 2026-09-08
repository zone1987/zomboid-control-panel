import { apiFetch } from '@/lib/api'

export type Item = {
  type: string
  name?: string
  icon?: string
  category?: string
  itemType?: string
  module?: string
  weight?: number
}

/**
 * Why the names are, or are not, in the reader's language.
 *
 * Five ways of having no translation used to look identical, so an
 * operator read English names with nothing said about it.
 */
export type TranslationState =
  | 'translated'
  | 'noSuchLanguage'
  | 'unsupportedLanguage'
  | 'pathMissing'
  | 'noCredentials'
  | 'unreachable'
  | 'unreadable'

export type TranslationVerdict = {
  state: TranslationState
  language: string | null
  path: string | null
  count: number
}

/** The states the operator can do something about. */
export const TRANSLATION_FAULTS: TranslationState[] = [
  'pathMissing',
  'unreachable',
  'unreadable',
]

export type ItemCatalogue = {
  items: Item[]
  generatedAt: number | null
  bridgeVersion: string | null
  available: boolean
  /** The language the names came back in, when the server had that file. */
  language?: string | null
  translation?: TranslationVerdict
  limits: { perCommand: number; maxTotal: number }
}

export type GiveResult = {
  type: string
  count: number
  reply: string
  failed: boolean
}

export function listItems(
  serverId: string,
  language: string,
  refresh = false,
): Promise<ItemCatalogue> {
  const query = new URLSearchParams({ language })

  if (refresh) {
    query.set('refresh', '1')
  }

  return apiFetch(`/servers/${serverId}/items?${query}`)
}

export function giveItems(
  serverId: string,
  username: string,
  items: { type: string; count: number }[],
): Promise<{ status: string; username: string; results: GiveResult[] }> {
  return apiFetch(`/servers/${serverId}/items/give`, {
    method: 'POST',
    body: { username, items },
  })
}

export function iconUrl(icon: string): string {
  return `/api/icons/${encodeURIComponent(icon)}.png`
}

/**
 * Items with no artwork of their own name their icon "None" or
 * "default" in the scripts. Asking for those is a request that can only
 * come back 404, so the placeholder is shown without asking.
 */
const NO_ICON = new Set(['none', 'default', ''])

export function hasIcon(item: Item): boolean {
  return item.icon !== undefined && !NO_ICON.has(item.icon.trim().toLowerCase())
}

export type IconStatus = { count: number; available: boolean; wanted: string[] }

export type IconUploadResult = {
  status: string
  count: number
  results: {
    name: string
    failed: boolean
    error?: string
    detail?: string
    extracted?: number
    pages?: number
    skipped?: number
  }[]
}

export function iconStatus(): Promise<IconStatus> {
  return apiFetch('/icons')
}

export function uploadIconPacks(files: File[], clear: boolean): Promise<IconUploadResult> {
  const form = new FormData()

  for (const file of files) {
    form.append('packs[]', file)
  }

  if (clear) {
    form.append('clear', '1')
  }

  return apiFetch('/icons/upload', { method: 'POST', body: form })
}

/**
 * Matches on the displayed name and on the type, so both "Axe" and
 * "Base.Axe" find the same thing. Words may come in any order, which is
 * what makes typing "axe wood" work.
 */
export function matchesSearch(item: Item, terms: string[]): boolean {
  if (terms.length === 0) {
    return true
  }

  const haystack = `${item.name ?? ''} ${item.type} ${item.category ?? ''}`.toLowerCase()

  return terms.every((term) => haystack.includes(term))
}

export function splitSearch(needle: string): string[] {
  return needle
    .trim()
    .toLowerCase()
    .split(/\s+/)
    .filter((term) => term !== '')
}

/** The name the bridge reported, or the type with its module stripped. */
export function displayName(item: Item): string {
  if (item.name !== undefined && item.name !== '') {
    return item.name
  }

  const withoutModule = item.type.includes('.') ? item.type.split('.').slice(1).join('.') : item.type

  // "WildGarlicCataplasm" reads better as "Wild Garlic Cataplasm", and
  // an underscore is a word break the display should honour — without
  // one, a name like "Wound_LHand_Laceration_Female" has nowhere to wrap.
  return withoutModule.replace(/_/g, ' ').replace(/([a-z0-9])([A-Z])/g, '$1 $2')
}
