import { apiFetch } from '@/lib/api'
import type { AssignableRole } from './invitations'

export type Account = {
  id: string
  email: string
  displayName: string
  roles: AssignableRole[]
  active: boolean
  locale: string
  hasPassword: boolean
  twoFactorEnabled: boolean
  passkeyCount: number
  identities: string[]
  createdAt: string
  lastLoginAt: string | null
  self: boolean
  assignedRoles: string[]
  permissions: string[]
}

export type AccountDraft = {
  displayName?: string
  email?: string
  roles?: AssignableRole[]
  assignedRoles?: string[]
  active?: boolean
  password?: string
}

export function listAccounts(): Promise<{
  items: Account[]
  assignableRoles: AssignableRole[]
  roles: { id: string; label: string; builtIn: boolean }[]
}> {
  return apiFetch('/accounts')
}

export function updateAccount(id: string, values: AccountDraft): Promise<Account> {
  return apiFetch<Account>(`/accounts/${id}`, { method: 'PATCH', body: values })
}

export function deleteAccount(id: string): Promise<{ status: string }> {
  return apiFetch(`/accounts/${id}`, { method: 'DELETE' })
}
