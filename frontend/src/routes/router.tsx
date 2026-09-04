import { createBrowserRouter } from 'react-router'

import { AppLayout } from '@/components/layout/app-layout'
import { LoginPage } from '@/features/auth/login-page'
import { ForgotPasswordPage } from '@/features/auth/forgot-password-page'
import { ResetPasswordPage } from '@/features/auth/reset-password-page'
import { AcceptInvitationPage } from '@/features/users/accept-invitation-page'
import { UsersPage } from '@/features/users/users-page'
import { SetupPage } from '@/features/setup/setup-page'
import { DashboardPage } from '@/features/dashboard/dashboard-page'
import { ProfilePage } from '@/features/profile/profile-page'
import { SettingsPage } from '@/features/settings/settings-page'
import { ServerListPage } from '@/features/servers/server-list-page'
import { ServerDetailPage } from '@/features/servers/server-detail-page'
import { HealthProbePage } from '@/features/dashboard/health-probe-page'
import { RequireAnonymous, RequireAuth, RequireRole, SetupGate } from './guards'

export const router = createBrowserRouter(
  [
    {
      element: <SetupGate />,
      children: [
        { path: 'setup', element: <SetupPage /> },
        {
          element: <RequireAnonymous />,
          children: [
            { path: 'login', element: <LoginPage /> },
            { path: 'forgot-password', element: <ForgotPasswordPage /> },
          ],
        },
        // Reachable while signed in too: a link from a mail should work
        // regardless of who happens to be logged in on that browser.
        { path: 'reset-password/:token', element: <ResetPasswordPage /> },
        { path: 'invitation/:token', element: <AcceptInvitationPage /> },
        {
          element: <RequireAuth />,
          children: [
            {
              path: '/',
              element: <AppLayout />,
              children: [
                { index: true, element: <DashboardPage /> },
                { path: 'profile', element: <ProfilePage /> },
                {
                  element: <RequireRole role="ROLE_SERVER_ADMIN" />,
                  children: [
                    { path: 'servers', element: <ServerListPage /> },
                    { path: 'servers/:id', element: <ServerDetailPage /> },
                  ],
                },
                {
                  element: <RequireRole role="ROLE_ADMIN" />,
                  children: [
                    { path: 'settings', element: <SettingsPage /> },
                    { path: 'users', element: <UsersPage /> },
                  ],
                },
                { path: 'health', element: <HealthProbePage /> },
              ],
            },
          ],
        },
      ],
    },
  ],
  { basename: '/app' },
)
