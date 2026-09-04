import { apiFetch } from '@/lib/api'

export const ASSIGNABLE_ROLES = ['ROLE_USER', 'ROLE_SERVER_ADMIN', 'ROLE_ADMIN'] as const

export type AssignableRole = (typeof ASSIGNABLE_ROLES)[number]

export type Invitation = {
  id: string
  email: string
  roles: string[]
  invitedBy: string | null
  createdAt: string
  expiresAt: string
  acceptedAt: string | null
  pending: boolean
}

export function listInvitations(): Promise<{ items: Invitation[] }> {
  return apiFetch<{ items: Invitation[] }>('/invitations')
}

export function createInvitation(email: string, roles: AssignableRole[]): Promise<{ email: string; expiresAt: string }> {
  return apiFetch('/invitations', { method: 'POST', body: { email, roles } })
}

export function revokeInvitation(id: string): Promise<void> {
  return apiFetch<void>(`/invitations/${id}`, { method: 'DELETE' })
}

export function inspectInvitation(token: string): Promise<{ valid: boolean; email: string }> {
  return apiFetch(`/invitations/accept/${token}`)
}

export function acceptInvitation(
  token: string,
  displayName: string,
  password: string,
): Promise<{ status: string }> {
  return apiFetch(`/invitations/accept/${token}`, {
    method: 'POST',
    body: { displayName, password },
  })
}
