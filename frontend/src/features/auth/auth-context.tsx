import { useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, use, useCallback, useMemo } from 'react'

import { apiFetch } from '@/lib/api'
import type { AuthenticatedUser, LoginResponse, Permission, SessionResponse } from './types'

type AuthContextValue = {
  user: AuthenticatedUser | null
  isLoading: boolean
  signIn: (email: string, password: string) => Promise<LoginResponse>
  submitTwoFactorCode: (code: string) => Promise<LoginResponse>
  signOut: () => Promise<void>
  refresh: () => Promise<void>
  hasRole: (role: string) => boolean
  can: (permission: Permission) => boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const queryClient = useQueryClient()

  const { data, isPending } = useQuery({
    queryKey: ['session'],
    queryFn: () => apiFetch<SessionResponse>('/session'),
    staleTime: 60_000,
  })

  const refresh = useCallback(async () => {
    await queryClient.invalidateQueries({ queryKey: ['session'] })
  }, [queryClient])

  const value = useMemo<AuthContextValue>(() => {
    const user = data?.user ?? null

    return {
      user,
      isLoading: isPending,
      hasRole: (role) => user?.roles.includes(role) ?? false,
      can: (permission) => user?.permissions.includes(permission) ?? false,
      refresh,
      signIn: async (email, password) => {
        const response = await apiFetch<LoginResponse>('/login', {
          method: 'POST',
          body: { email, password },
        })

        if (response.twoFactorComplete) {
          await refresh()
        }

        return response
      },
      submitTwoFactorCode: async (code) => {
        const response = await apiFetch<LoginResponse>('/2fa', {
          method: 'POST',
          body: { code },
        })

        await refresh()

        return response
      },
      signOut: async () => {
        await apiFetch<unknown>('/logout', { method: 'POST' })

        // Clearing before refetching would drop the session query itself,
        // leaving the interface showing a user who is no longer signed in.
        queryClient.removeQueries({ predicate: (q) => q.queryKey[0] !== 'session' })
        await queryClient.refetchQueries({ queryKey: ['session'] })
      },
    }
  }, [data, isPending, queryClient, refresh])

  return <AuthContext value={value}>{children}</AuthContext>
}

export function useAuth(): AuthContextValue {
  const context = use(AuthContext)

  if (!context) {
    throw new Error('useAuth must be used inside an AuthProvider.')
  }

  return context
}
