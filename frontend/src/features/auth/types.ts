export type AuthenticatedUser = {
  id: string
  email: string
  displayName: string
  roles: string[]
  locale: string
  twoFactorEnabled: boolean
  passkeyCount: number
  linkedProviders: string[]
}

export type SessionResponse = {
  authenticated: boolean
  user: AuthenticatedUser | null
}

export type LoginResponse =
  | { status: 'authenticated'; twoFactorComplete: true; user: AuthenticatedUser }
  | { status: 'two_factor_required'; twoFactorComplete: false; availableProviders: string[] }

export type SetupStatus = {
  setupComplete: boolean
}
