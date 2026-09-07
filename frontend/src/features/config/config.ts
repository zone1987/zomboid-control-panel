import { apiFetch } from '@/lib/api'
import { INI_LABELS, SANDBOX_LABELS } from './labels'

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
 * The label to show, in order of who says it best.
 *
 * The game's own translation first, because it is the wording a player
 * already knows. Then the panel's own table, which exists because the
 * game translates none of the 144 INI options and 17 of the sandbox
 * ones. The technical key last — it is never lost, since the row shows
 * it underneath either way.
 */
export function labelOf(value: ConfigValue, language: string): string {
  const german = language.startsWith('de')
  const localised = value.labels
  const fromGame = (german ? localised?.DE : localised?.EN) ?? localised?.EN

  if (fromGame !== null && fromGame !== undefined && fromGame !== '') {
    // 80 of the game's labels end in a colon, because on its own screen
    // they sit to the left of the control. Here they sit above it, and
    // "Tageslänge:" over a select reads as an unfinished sentence.
    return fromGame.replace(/\s*:\s*$/, '')
  }

  const ours = SANDBOX_LABELS[value.key] ?? INI_LABELS[value.key]

  if (ours !== undefined) {
    return german ? ours.de : ours.en
  }

  return value.key
}

export function tooltipOf(value: ConfigValue, language: string): string | null {
  const localised = value.tooltips
  const preferred = language.startsWith('de') ? localised?.DE : localised?.EN
  const text = preferred ?? localised?.EN ?? null

  return text === null ? null : cleanExplanation(text)
}

/**
 * The game's explanation, as prose rather than as its own markup.
 *
 * 27 of them carry `<br>` as a line break — it is markup for the game's
 * own text renderer, and shown verbatim it reads as a mistake. Turned
 * into a real break, and the escaped quotes the translations use are
 * unescaped for the same reason.
 */
export function cleanExplanation(text: string): string {
  return text
    .replaceAll(/<br\s*\/?>/gi, '\n')
    .replaceAll('\\"', '"')
    .replaceAll(/[ \t]+\n/g, '\n')
    .trim()
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

/** What the panel may hear back from a save. */
export type ConfigApplyOutcome =
  | 'applied'
  | 'restartNeeded'
  | 'reloadUnconfirmed'
  | 'reloadUnknown'
  | 'noRcon'

export type ConfigBackupState = 'backed-up' | 'nothing-to-back-up' | 'failed'

export type ConfigWriteResult = {
  status: 'written'
  path: string
  written: string[]
  backup: { state: ConfigBackupState; path: string | null; error: string | null }
  apply: ConfigApplyOutcome
  /** True for every outcome but `applied` — in doubt, a restart is needed. */
  restartNeeded: boolean
  applyMessage: string
}

/**
 * Saves the values that changed, and only those.
 *
 * A full snapshot would resend every one of 270 values, which is how the
 * reference panel destroyed duplicate keys and overwrote masked secrets
 * with their own mask. The body carries the edits alone.
 */
export function writeConfig(
  serverId: string,
  kind: ConfigKind,
  changes: Record<string, boolean | number | string>,
): Promise<ConfigWriteResult> {
  return apiFetch(`/servers/${serverId}/config/${kind}`, {
    method: 'PATCH',
    body: { changes },
  })
}

/**
 * Whether a value is one this panel offers a control for.
 *
 * `text` is multi-line prose (the welcome message) and is left to a
 * later step rather than squeezed into a single-line field; a value
 * whose type could not be established has no control that would be
 * honest. Both are shown, and shown as not editable here.
 */
export function isEditable(value: ConfigValue): boolean {
  return value.type !== 'text'
}

/**
 * Parses what somebody typed into the type the file holds.
 *
 * Returns null when the text is not a value of that type, so the caller
 * can refuse rather than write a zero it invented.
 */
export function parseInput(value: ConfigValue, text: string): boolean | number | string | null {
  if (value.type === 'boolean') {
    return text === 'true'
  }

  if (value.type === 'integer' || value.type === 'enum') {
    if (!/^-?\d+$/.test(text.trim())) {
      return null
    }

    return Number.parseInt(text, 10)
  }

  if (value.type === 'double') {
    const parsed = Number(text.trim().replace(',', '.'))

    return text.trim() === '' || Number.isNaN(parsed) ? null : parsed
  }

  return text
}

/**
 * Whether a number is inside the game's own bounds.
 *
 * The game clamps silently — `setAdminValue` proved that — so a value it
 * would refuse must be caught here rather than written and reported as
 * saved.
 */
export function outsideBounds(value: ConfigValue, next: boolean | number | string): boolean {
  if (typeof next !== 'number' || value.min === null || value.max === null) {
    return false
  }

  return next < value.min || next > value.max
}
