import { apiFetch } from '@/lib/api'

export type PanelVersion = {
  current: string
  /** Null when the release could not be read; not the same as "current". */
  latest: string | null
  upToDate: boolean | null
  url: string | null
  /** The operator switched background deployment on. */
  autoDeploy?: boolean
  /** A deployment for the newest version is claimed and running. */
  deploying?: boolean
  /** An open panel should take the new version by itself. */
  reloadPanel?: boolean
}

export function getPanelVersion(): Promise<PanelVersion> {
  return apiFetch<PanelVersion>('/panel/version')
}

/** An update is worth showing only when a newer release was actually read. */
export function hasUpdate(version: PanelVersion | undefined): boolean {
  return version?.upToDate === false && version.latest !== null
}
