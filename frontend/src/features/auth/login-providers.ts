import { apiFetch } from '@/lib/api'

/** Which sign-in providers the login page can usefully offer. */
export type LoginProviders = {
  google: boolean
  steam: boolean
}

/**
 * A provider nobody has linked can only ever answer "no such account",
 * which is the one failure the login page has no way to explain.
 *
 * Linking stays available under Account, so this narrows what is offered
 * before a login and never what an operator can set up.
 */
export function getLoginProviders(): Promise<LoginProviders> {
  return apiFetch<LoginProviders>('/login/providers')
}
