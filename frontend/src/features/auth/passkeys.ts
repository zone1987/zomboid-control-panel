import {
  startAuthentication,
  startRegistration,
  browserSupportsWebAuthn,
  platformAuthenticatorIsAvailable,
} from '@simplewebauthn/browser'
import type {
  PublicKeyCredentialCreationOptionsJSON,
  PublicKeyCredentialRequestOptionsJSON,
} from '@simplewebauthn/browser'

import { apiFetch } from '@/lib/api'
import type { AuthenticatedUser } from './types'

export type Passkey = {
  id: string
  name: string
  createdAt: string
  lastUsedAt: string | null
}

export { browserSupportsWebAuthn, platformAuthenticatorIsAvailable }

/**
 * Registers an additional passkey for the signed-in account. The server
 * derives the account from the session, so no identifier is sent.
 */
export async function registerPasskey(deviceName: string): Promise<Passkey> {
  const options = await apiFetch<PublicKeyCredentialCreationOptionsJSON>(
    '/passkeys/register/options',
    { method: 'POST', body: {} },
  )

  const attestation = await startRegistration({ optionsJSON: options })

  const result = await apiFetch<{ credential: Passkey }>('/passkeys/register', {
    method: 'POST',
    body: { ...attestation, deviceName },
  })

  return result.credential
}

/**
 * Signs in with a passkey. Passing no email lets the authenticator offer
 * whichever resident credential it holds for this site.
 */
export async function signInWithPasskey(email?: string): Promise<AuthenticatedUser> {
  const options = await apiFetch<PublicKeyCredentialRequestOptionsJSON>('/passkeys/login/options', {
    method: 'POST',
    body: email ? { username: email } : {},
  })

  const assertion = await startAuthentication({ optionsJSON: options })

  const result = await apiFetch<{ user: AuthenticatedUser }>('/passkeys/login', {
    method: 'POST',
    body: assertion,
  })

  return result.user
}

export function listPasskeys(): Promise<{ items: Passkey[] }> {
  return apiFetch<{ items: Passkey[] }>('/passkeys')
}

export function renamePasskey(id: string, name: string): Promise<Passkey> {
  return apiFetch<Passkey>(`/passkeys/${id}`, { method: 'PATCH', body: { name } })
}

export function deletePasskey(id: string): Promise<void> {
  return apiFetch<void>(`/passkeys/${id}`, { method: 'DELETE' })
}

/**
 * A cancelled prompt is a normal outcome, not an error worth reporting.
 */
export function isUserCancellation(error: unknown): boolean {
  return (
    error instanceof Error &&
    (error.name === 'NotAllowedError' || error.name === 'AbortError')
  )
}
