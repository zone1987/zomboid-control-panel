import { apiFetch } from '@/lib/api'

export type PermissionEntry = {
  name: string
  sensitive: boolean
}

export type PermissionCatalogue = Record<string, PermissionEntry[]>

export type PanelRole = {
  id: string
  name: string
  label: string
  permissions: string[]
  builtIn: boolean
}

export function listRoles(): Promise<{ items: PanelRole[]; permissions: PermissionCatalogue }> {
  return apiFetch('/roles')
}

export function createRole(label: string, permissions: string[]): Promise<PanelRole> {
  return apiFetch('/roles', { method: 'POST', body: { label, permissions } })
}

export function updateRole(
  id: string,
  values: { label?: string; permissions?: string[] },
): Promise<PanelRole> {
  return apiFetch(`/roles/${id}`, { method: 'PATCH', body: values })
}

export function deleteRole(id: string): Promise<void> {
  return apiFetch(`/roles/${id}`, { method: 'DELETE' })
}

/** The order the groups are shown in; anything unlisted follows. */
export const GROUP_ORDER = ['players', 'world', 'communication', 'servers', 'administration']

export function sortGroups(catalogue: PermissionCatalogue): [string, PermissionEntry[]][] {
  return Object.entries(catalogue).sort(([a], [b]) => {
    const left = GROUP_ORDER.indexOf(a)
    const right = GROUP_ORDER.indexOf(b)

    return (left === -1 ? GROUP_ORDER.length : left) - (right === -1 ? GROUP_ORDER.length : right)
  })
}
