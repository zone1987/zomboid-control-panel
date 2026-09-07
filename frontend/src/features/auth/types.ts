/** Mirrors App\Security\Permission\Permission on the backend. */
export type Permission =
  | 'players.view'
  | 'players.kick'
  | 'players.ban'
  | 'players.teleport'
  | 'players.accessLevel'
  | 'items.give'
  | 'log.view'
  | 'chat.read'
  | 'chat.send'
  | 'console.use'
  | 'events.trigger'
  | 'vehicles.view'
  | 'servers.view'
  | 'servers.edit'
  | 'servers.bridge'
  | 'servers.config'
  | 'users.invite'
  | 'users.manage'
  | 'settings.edit'

export type AuthenticatedUser = {
  id: string
  email: string
  displayName: string
  roles: string[]
  permissions: Permission[]
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
