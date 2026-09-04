import { createBrowserRouter } from 'react-router'

import { AppLayout } from '@/components/layout/app-layout'
import { LoginPage } from '@/features/auth/login-page'
import { RequireAnonymous, RequireAuth, RequireRole, SetupGate } from './guards'

// Only the sign-in screen ships in the first bundle; everything behind it
// is fetched when the route is first visited.
export const router = createBrowserRouter(
  [
    {
      element: <SetupGate />,
      children: [
        {
          path: 'setup',
          lazy: async () => ({ Component: (await import('@/features/setup/setup-page')).SetupPage }),
        },
        {
          element: <RequireAnonymous />,
          children: [
            { path: 'login', element: <LoginPage /> },
            {
              path: 'forgot-password',
              lazy: async () => ({
                Component: (await import('@/features/auth/forgot-password-page')).ForgotPasswordPage,
              }),
            },
          ],
        },
        // Reachable while signed in too: a link from a mail should work
        // regardless of who happens to be logged in on that browser.
        {
          path: 'reset-password/:token',
          lazy: async () => ({
            Component: (await import('@/features/auth/reset-password-page')).ResetPasswordPage,
          }),
        },
        {
          path: 'invitation/:token',
          lazy: async () => ({
            Component: (await import('@/features/users/accept-invitation-page')).AcceptInvitationPage,
          }),
        },
        {
          element: <RequireAuth />,
          children: [
            {
              path: '/',
              element: <AppLayout />,
              children: [
                {
                  index: true,
                  lazy: async () => ({
                    Component: (await import('@/features/dashboard/dashboard-page')).DashboardPage,
                  }),
                },
                {
                  path: 'profile',
                  lazy: async () => ({
                    Component: (await import('@/features/profile/profile-page')).ProfilePage,
                  }),
                },
                {
                  element: <RequireRole role="ROLE_SERVER_ADMIN" />,
                  children: [
                    {
                      path: 'servers',
                      lazy: async () => ({
                        Component: (await import('@/features/servers/server-list-page')).ServerListPage,
                      }),
                    },
                    {
                      path: 'servers/:id',
                      lazy: async () => ({
                        Component: (await import('@/features/servers/server-detail-page'))
                          .ServerDetailPage,
                      }),
                    },
                    {
                      path: 'servers/:id/players',
                      lazy: async () => ({
                        Component: (await import('@/features/players/players-page')).PlayersPage,
                      }),
                    },
                    {
                      path: 'servers/:id/logs',
                      lazy: async () => ({
                        Component: (await import('@/features/logs/logs-page')).LogsPage,
                      }),
                    },
                    {
                      path: 'servers/:id/items',
                      lazy: async () => ({
                        Component: (await import('@/features/items/items-page')).ItemsPage,
                      }),
                    },
                    {
                      path: 'servers/:id/chat',
                      lazy: async () => ({
                        Component: (await import('@/features/chat/chat-page')).ChatPage,
                      }),
                    },
                    {
                      path: 'servers/:id/console',
                      lazy: async () => ({
                        Component: (await import('@/features/console/console-page')).ConsolePage,
                      }),
                    },
                  ],
                },
                {
                  element: <RequireRole role="ROLE_ADMIN" />,
                  children: [
                    {
                      path: 'settings',
                      lazy: async () => ({
                        Component: (await import('@/features/settings/settings-page')).SettingsPage,
                      }),
                    },
                    {
                      path: 'users',
                      lazy: async () => ({
                        Component: (await import('@/features/users/users-page')).UsersPage,
                      }),
                    },
                  ],
                },
                {
                  path: 'health',
                  lazy: async () => ({
                    Component: (await import('@/features/dashboard/health-probe-page')).HealthProbePage,
                  }),
                },
              ],
            },
          ],
        },
      ],
    },
  ],
  { basename: '/app' },
)
