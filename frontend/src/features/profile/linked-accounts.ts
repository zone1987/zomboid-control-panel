import { apiFetch } from '@/lib/api'

export type LinkedAccount = {
  provider: 'google' | 'steam'
  label: string | null
  linkedAt: string
}

export function listLinkedAccounts(): Promise<{ items: LinkedAccount[] }> {
  return apiFetch<{ items: LinkedAccount[] }>('/connect')
}

export function unlinkAccount(provider: string): Promise<{ status: string }> {
  return apiFetch<{ status: string }>(`/connect/${provider}`, { method: 'DELETE' })
}

/** Linking leaves the SPA, so this is a navigation rather than a fetch. */
export function startLinking(provider: 'google' | 'steam'): void {
  // Both use their own `/link` route rather than the sign-in one: that
  // route authenticates whoever returns and lands them on the dashboard,
  // so a successful link looked like nothing had happened.
  window.location.href = `/api/connect/${provider}/link`
}
