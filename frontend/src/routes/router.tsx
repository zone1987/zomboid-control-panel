import { createBrowserRouter } from 'react-router'

import { AppLayout } from '@/components/layout/app-layout'
import { LoginPage } from '@/features/auth/login-page'
import { SetupPage } from '@/features/setup/setup-page'
import { DashboardPage } from '@/features/dashboard/dashboard-page'
import { ProfilePage } from '@/features/profile/profile-page'
import { HealthProbePage } from '@/features/dashboard/health-probe-page'
import { RequireAnonymous, RequireAuth, SetupGate } from './guards'

export const router = createBrowserRouter(
  [
    {
      element: <SetupGate />,
      children: [
        { path: 'setup', element: <SetupPage /> },
        {
          element: <RequireAnonymous />,
          children: [{ path: 'login', element: <LoginPage /> }],
        },
        {
          element: <RequireAuth />,
          children: [
            {
              path: '/',
              element: <AppLayout />,
              children: [
                { index: true, element: <DashboardPage /> },
                { path: 'profile', element: <ProfilePage /> },
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
