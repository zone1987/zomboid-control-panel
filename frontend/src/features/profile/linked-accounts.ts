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
  window.location.href = provider === 'steam' ? '/api/connect/steam/link' : '/api/connect/google'
}
