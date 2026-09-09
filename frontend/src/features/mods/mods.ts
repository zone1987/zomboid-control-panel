import { apiFetch } from '@/lib/api'

/**
 * How this mod stands against the build the selected server runs.
 *
 * Four values rather than a boolean: a mod declaring nothing is not
 * the same as one that matches, and neither is the same as nobody
 * having told the panel what the server runs.
 */
export type BuildVerdict = 'match' | 'mismatch' | 'undeclared' | 'unknown'

/**
 * Why a lookup ended as it did.
 *
 * `noKey` is the one an operator can fix in a minute, and it is not a
 * failure of the panel — searching simply needs a Steam Web API key.
 */
export type WorkshopState = 'ok' | 'noKey' | 'rateLimited' | 'unreachable' | 'notFound'

export type Mod = {
  workshopId: string
  /** False when the workshop could not describe it — still installed. */
  resolved: boolean
  title: string | null
  description: string | null
  previewUrl: string | null
  tags: string[]
  fileSize: number | null
  createdAt: string | null
  updatedAt: string | null
  subscriptions: number | null
  favourites: number | null
  views: number | null
  dependencies: string[]
  isCollection: boolean
  isMap: boolean
  declaredBuild: string | null
  declaredBuilds: string[]
  buildVerdict: BuildVerdict
  url: string
}

/** Where the mod list lives, or why there is none to write. */
export type ModFileState = 'found' | 'noTransfer' | 'noFile' | 'ambiguous'

/**
 * Which build the server runs, and where that was learnt.
 *
 * Two sources kept apart on purpose: the bridge reports what the game
 * *is*, a typed value is what somebody *believes*, and where they
 * disagree that is worth saying rather than silently preferring one.
 */
export type BuildReading = {
  build: string | null
  source: 'bridge' | 'entered' | 'unknown'
  reported: string | null
  entered: string | null
  /** The whole version, for showing rather than filtering. */
  fullVersion: string | null
  disagrees: boolean
}

export type InstalledMods = {
  state: ModFileState
  path: string | null
  /** Lines the file does not have; they cannot be written into it. */
  missingKeys: string[]
  workshopState: WorkshopState
  hasKey: boolean
  maps: string[]
  modIds: string[]
  gameBuild: string | null
  buildReading: BuildReading | null
  items: Mod[]
}

export type ModSearch = {
  state: WorkshopState
  hasKey: boolean
  total: number
  gameBuild: string | null
  buildReading: BuildReading | null
  /** The tag the server's build added, so the screen can say so. */
  buildFilter: string | null
  items: Mod[]
}

/**
 * One mod in the requirement chain.
 *
 * `repeats` marks a mod already above it in the branch: expanding it
 * again is what a circle does, so it is named and left closed.
 */
export type DependencyNode = {
  workshopId: string
  title: string | null
  /** False when the workshop could not describe it — still required. */
  resolved: boolean
  repeats: boolean
  children: DependencyNode[]
}

export type DependencyTree = {
  state: WorkshopState
  nodes: DependencyNode[]
  /** The walk hit its depth limit, so the chain may go further. */
  truncated: boolean
}

export type ModDetail = {
  state: WorkshopState
  hasKey: boolean
  gameBuild: string | null
  item: Mod | null
  dependencies: Mod[]
  tree: DependencyTree | null
}

export type ModChange = {
  status:
    | 'written' | 'notVerified' | 'keysMissing' | 'refused'
    | 'alreadyInstalled' | 'notInstalled' | 'alreadyOrdered' | 'alreadyListed' | 'cycle'
    | ModFileState
  missingKeys: string[]
  written?: string[]
  verified?: boolean
  mismatched?: string[]
  restored?: boolean
}

/**
 * Why a workshop item's mod ids are unknown.
 *
 * `notDownloaded` is the benign one and by far the commonest: the
 * server fetches an item at its next start, so it resolves itself.
 */
export type ModIdState =
  | 'found'
  | 'notDownloaded'
  | 'noModInfo'
  | 'noWorkshopDirectory'
  | 'noTransfer'
  | 'unreachable'

export type ModIdVerdict = {
  state: ModIdState
  /** The `id=` values, which are what `Mods=` needs. */
  ids: string[]
  /** Where each was read from, so the operator can check. */
  paths: string[]
  versionMin: string | null
  /** Map folders this item ships; `Map=` takes these. */
  maps: string[]
}

export type LoadOrderVerdict = {
  state: 'sorted' | 'cycle'
  order: string[]
  /** False when the file already holds this order — nothing to apply. */
  changed: boolean
  tangled: string[]
}

export type ModDiagnosis = {
  state: ModFileState | 'unreachable'
  modIds: Record<string, ModIdVerdict>
  missingDependencies: string[]
  loadOrder: LoadOrderVerdict
  /** The dependency walk hit its depth limit, so this is not the whole set. */
  truncated: boolean
  /** In Mods= but belonging to no installed item. */
  orphanedModIds: string[]
  /** Installed but absent from Mods=, so downloaded and never loaded. */
  unmappedWorkshopIds: string[]
  /** Shipped by an installed mod but absent from Map=, so invisible. */
  unlistedMaps: string[]
}

export function diagnoseMods(serverId: string): Promise<ModDiagnosis> {
  return apiFetch<ModDiagnosis>(`/servers/${serverId}/mods/diagnosis`)
}

/** Whether anything here is worth putting in front of the operator. */
export function hasFindings(diagnosis: ModDiagnosis | undefined): boolean {
  if (diagnosis === undefined || diagnosis.state !== 'found') {
    return false
  }

  return (
    diagnosis.missingDependencies.length > 0
    || diagnosis.orphanedModIds.length > 0
    || diagnosis.unmappedWorkshopIds.length > 0
    || diagnosis.unlistedMaps.length > 0
    || diagnosis.loadOrder.state === 'cycle'
    || diagnosis.loadOrder.changed
  )
}

export type SortOrder = 'trend' | 'subscriptions' | 'updated' | 'recent'

export function listInstalled(serverId: string): Promise<InstalledMods> {
  return apiFetch<InstalledMods>(`/servers/${serverId}/mods/installed`)
}

export function searchMods(
  serverId: string,
  options: { term?: string; tags?: string[]; sort?: SortOrder; page?: number },
): Promise<ModSearch> {
  const query = new URLSearchParams()

  if (options.term !== undefined && options.term !== '') {
    query.set('q', options.term)
  }

  for (const tag of options.tags ?? []) {
    query.append('tags[]', tag)
  }

  query.set('sort', options.sort ?? 'trend')
  query.set('page', String(options.page ?? 1))

  return apiFetch<ModSearch>(`/servers/${serverId}/mods/search?${query.toString()}`)
}

export function modDetail(serverId: string, workshopId: string): Promise<ModDetail> {
  return apiFetch<ModDetail>(`/servers/${serverId}/mods/${encodeURIComponent(workshopId)}`)
}

export function listMaps(serverId: string): Promise<ModChange> {
  return apiFetch<ModChange>(`/servers/${serverId}/mods/maps`, { method: 'POST', body: {} })
}

export function applyLoadOrder(serverId: string): Promise<ModChange & { tangled?: string[] }> {
  return apiFetch<ModChange & { tangled?: string[] }>(`/servers/${serverId}/mods/order`, {
    method: 'POST',
    body: {},
  })
}

export function addMod(serverId: string, workshopId: string): Promise<ModChange> {
  // The object, not a string: apiFetch stringifies it itself (rule 10f2).
  return apiFetch<ModChange>(`/servers/${serverId}/mods/installed`, {
    method: 'POST',
    body: { workshopId },
  })
}

export function removeMod(serverId: string, workshopId: string): Promise<ModChange> {
  return apiFetch<ModChange>(
    `/servers/${serverId}/mods/installed/${encodeURIComponent(workshopId)}`,
    { method: 'DELETE' },
  )
}

/**
 * Pulls a workshop id out of whatever the operator pasted.
 *
 * A URL is what a browser gives you when you copy from the workshop,
 * and asking somebody to extract the number by hand is the kind of
 * work a panel exists to remove.
 */
export function readWorkshopId(input: string): string | null {
  const trimmed = input.trim()

  if (/^\d{1,20}$/.test(trimmed)) {
    return trimmed
  }

  const fromUrl = /[?&]id=(\d{1,20})\b/.exec(trimmed)

  return fromUrl === null ? null : fromUrl[1]
}

/** The tags worth offering as filters, most used first. */
export function categoriesOf(mods: Mod[]): { tag: string; count: number }[] {
  const counts = new Map<string, number>()

  for (const mod of mods) {
    for (const tag of mod.tags) {
      // The build is already applied by the server, so offering it as a
      // filter would be a control that changes nothing.
      if (/^Build \d+$/.test(tag)) {
        continue
      }

      counts.set(tag, (counts.get(tag) ?? 0) + 1)
    }
  }

  return [...counts.entries()]
    .map(([tag, count]) => ({ tag, count }))
    .sort((a, b) => b.count - a.count || a.tag.localeCompare(b.tag))
}

/** Matches a mod against a search term, by title and by author-visible tags. */
export function matchesSearch(mod: Mod, needle: string): boolean {
  if (needle === '') {
    return true
  }

  const haystack = [mod.title ?? '', mod.workshopId, ...mod.tags].join(' ').toLowerCase()

  return needle
    .toLowerCase()
    .split(/\s+/)
    .filter((word) => word !== '')
    .every((word) => haystack.includes(word))
}

/** What to call a mod the workshop could not describe. */
export function displayName(mod: Mod): string {
  return mod.title ?? mod.workshopId
}

export function formatSize(bytes: number | null): string | null {
  if (bytes === null || bytes <= 0) {
    return null
  }

  const megabytes = bytes / 1048576

  return megabytes >= 1
    ? `${megabytes.toFixed(1)} MB`
    : `${Math.max(1, Math.round(bytes / 1024))} KB`
}
