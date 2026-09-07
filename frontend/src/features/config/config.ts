import { apiFetch } from '@/lib/api'

/** Which file a page is showing. */
export type ConfigKind = 'sandbox' | 'ini'

/** The two languages the game's own labels come in. */
export type Localised = { EN: string | null; DE: string | null }

export type ConfigValue = {
  key: string
  section: string | null
  value: boolean | number | string
  type: 'boolean' | 'double' | 'integer' | 'enum' | 'string' | 'text'
  /**
   * False for a value this game build does not define — a mod's. It is
   * shown and preserved rather than hidden, because a save built from
   * the schema alone would delete it.
   */
  known: boolean
  group: string | null
  min: number | null
  max: number | null
  numValues: number | null
  labels: Localised | null
  tooltips: Localised | null
  choices: { EN: Record<string, string | null>; DE: Record<string, string | null> } | null
  default: boolean | number | string | null
  /** The game generates this one per server, so there is no default to compare. */
  defaultIsGenerated: boolean
  /** The stored value is outside what this build knows, and stays untouched. */
  outOfRange: boolean
}

export type ConfigGroup = { name: string; options: string[] }

export type ConfigFile = {
  kind: ConfigKind
  path: string
  /** More than one match is the operator's choice, not an error. */
  candidates: string[]
  buildId: string | null
  groups: ConfigGroup[]
  values: ConfigValue[]
  unknown: number
}

export type ConfigFiles = {
  directory: string | null
  searched: string[]
  ini: string[]
  sandbox: string[]
  spawnRegions: string[]
  spawnPoints: string[]
  error: string | null
}

export function getConfigFiles(serverId: string): Promise<ConfigFiles> {
  return apiFetch(`/servers/${serverId}/config/files`)
}

export function readConfig(serverId: string, kind: ConfigKind): Promise<ConfigFile> {
  return apiFetch(`/servers/${serverId}/config/${kind}`)
}

/**
 * The label to show, falling back through the game's own translations
 * to the technical key.
 *
 * The INI has no translated names at all — only tooltips — so its rows
 * show the key, which is also what a forum post or a wiki page names.
 */
export function labelOf(value: ConfigValue, language: string): string {
  const localised = value.labels
  const preferred = language.startsWith('de') ? localised?.DE : localised?.EN

  return preferred ?? localised?.EN ?? value.key
}

export function tooltipOf(value: ConfigValue, language: string): string | null {
  const localised = value.tooltips
  const preferred = language.startsWith('de') ? localised?.DE : localised?.EN

  return preferred ?? localised?.EN ?? null
}

/**
 * An enum's choices in display order, one-based as the file stores them.
 *
 * Falls back to the bare number where a label is missing: showing "3"
 * is honest, and inventing a name for it is not.
 */
export function choicesOf(value: ConfigValue, language: string): { value: number; label: string }[] {
  if (value.numValues === null) {
    return []
  }

  const table = (language.startsWith('de') ? value.choices?.DE : value.choices?.EN) ?? value.choices?.EN

  return Array.from({ length: value.numValues }, (_, index) => {
    const at = index + 1

    return { value: at, label: table?.[String(at)] ?? String(at) }
  })
}

/** How many values a group holds in this particular file. */
export function countIn(group: ConfigGroup, values: ConfigValue[]): number {
  const held = new Set(values.map((value) => value.key))

  return group.options.filter((key) => held.has(key)).length
}

/**
 * Matches a search term against everything a row shows.
 *
 * The key as well as the label, because somebody arriving from a forum
 * post knows `ZombieLore.Speed` rather than "Geschwindigkeit".
 */
export function matches(value: ConfigValue, term: string, language: string): boolean {
  if (term === '') {
    return true
  }

  const needle = term.toLowerCase()

  return (
    value.key.toLowerCase().includes(needle) ||
    labelOf(value, language).toLowerCase().includes(needle) ||
    (tooltipOf(value, language) ?? '').toLowerCase().includes(needle)
  )
}
