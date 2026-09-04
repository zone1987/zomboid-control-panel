import { Navigate, Outlet, useLocation } from 'react-router'
import { useQuery } from '@tanstack/react-query'

import { apiFetch } from '@/lib/api'
import { useAuth } from '@/features/auth/auth-context'
import type { Permission, SetupStatus } from '@/features/auth/types'
import { FullPageSpinner } from '@/components/full-page-spinner'

/**
 * Sends first-time visitors to the wizard, and everyone else away from it.
 */
export function SetupGate() {
  const location = useLocation()

  const { data, isPending } = useQuery({
    queryKey: ['setup-status'],
    queryFn: () => apiFetch<SetupStatus>('/setup/status'),
    staleTime: Number.POSITIVE_INFINITY,
  })

  if (isPending) {
    return <FullPageSpinner />
  }

  const onSetupRoute = location.pathname.startsWith('/setup')

  if (!data?.setupComplete && !onSetupRoute) {
    return <Navigate to="/setup" replace />
  }

  if (data?.setupComplete && onSetupRoute) {
    return <Navigate to="/login" replace />
  }

  return <Outlet />
}

export function RequireAuth() {
  const { user, isLoading } = useAuth()
  const location = useLocation()

  if (isLoading) {
    return <FullPageSpinner />
  }

  if (!user) {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  return <Outlet />
}

export function RequireAnonymous() {
  const { user, isLoading } = useAuth()

  if (isLoading) {
    return <FullPageSpinner />
  }

  if (user) {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}

export function RequireRole({ role }: { role: string }) {
  const { hasRole, isLoading } = useAuth()

  if (isLoading) {
    return <FullPageSpinner />
  }

  if (!hasRole(role)) {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}

/**
 * Guards a route by what the user may do rather than what they are
 * called. Any one of the permissions is enough, so a section stays
 * reachable for a role holding only part of it.
 */
export function RequirePermission({ anyOf }: { anyOf: Permission[] }) {
  const { can, isLoading } = useAuth()

  if (isLoading) {
    return <FullPageSpinner />
  }

  if (!anyOf.some((permission) => can(permission))) {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}
