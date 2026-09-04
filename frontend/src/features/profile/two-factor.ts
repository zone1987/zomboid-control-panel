import { apiFetch } from '@/lib/api'

export type TwoFactorStatus = {
  enabled: boolean
  backupCodesRemaining: number
}

export type TwoFactorSetup = {
  secret: string
  qrContent: string
}

export type TwoFactorActivation = {
  status: string
  backupCodes: string[]
}

export function getTwoFactorStatus(): Promise<TwoFactorStatus> {
  return apiFetch<TwoFactorStatus>('/two-factor/status')
}

export function startTwoFactorSetup(): Promise<TwoFactorSetup> {
  return apiFetch<TwoFactorSetup>('/two-factor/setup', { method: 'POST', body: {} })
}

export function activateTwoFactor(code: string): Promise<TwoFactorActivation> {
  return apiFetch<TwoFactorActivation>('/two-factor/activate', { method: 'POST', body: { code } })
}

export function regenerateBackupCodes(password: string): Promise<TwoFactorActivation> {
  return apiFetch<TwoFactorActivation>('/two-factor/backup-codes', {
    method: 'POST',
    body: { password },
  })
}

export function disableTwoFactor(password: string): Promise<{ status: string }> {
  return apiFetch<{ status: string }>('/two-factor', { method: 'DELETE', body: { password } })
}

/** Groups the base32 secret so it can be typed in by hand without losing place. */
export function formatSecret(secret: string): string {
  return secret.replace(/(.{4})/g, '$1 ').trim()
}
