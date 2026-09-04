import { apiFetch } from '@/lib/api'

export function requestPasswordReset(email: string): Promise<{ status: string }> {
  return apiFetch('/password-reset', { method: 'POST', body: { email } })
}

export function inspectResetToken(token: string): Promise<{ valid: boolean }> {
  return apiFetch(`/password-reset/${token}`)
}

export function completePasswordReset(token: string, password: string): Promise<{ status: string }> {
  return apiFetch(`/password-reset/${token}`, { method: 'POST', body: { password } })
}
