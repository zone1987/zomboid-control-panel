import { createBrowserRouter } from 'react-router'

import { AppLayout } from '@/components/layout/app-layout'
import { LoginPage } from '@/features/auth/login-page'
import { RequireAnonymous, RequireAuth, RequirePermission, SetupGate } from './guards'
import { lazyRoute } from './lazy-route'
import { RouteError } from './route-error'

// Only the sign-in screen ships in the first bundle; everything behind it
// is fetched when the route is first visited.
export const router = createBrowserRouter(
  [
    {
      element: <SetupGate />,
      // One place for anything a route throws, so a failure is a page
      // rather than React Router's own developer screen.
      errorElement: <RouteError />,
      children: [
        {
          path: 'setup',
          lazy: lazyRoute(() => import('@/features/setup/setup-page'), 'SetupPage'),
        },
        {
          element: <RequireAnonymous />,
          children: [
            { path: 'login', element: <LoginPage /> },
            {
              path: 'forgot-password',
              lazy: lazyRoute(() => import('@/features/auth/forgot-password-page'), 'ForgotPasswordPage'),
            },
          ],
        },
        // Reachable while signed in too: a link from a mail should work
        // regardless of who happens to be logged in on that browser.
        {
          path: 'reset-password/:token',
          lazy: lazyRoute(() => import('@/features/auth/reset-password-page'), 'ResetPasswordPage'),
        },
        {
          path: 'invitation/:token',
          lazy: lazyRoute(() => import('@/features/users/accept-invitation-page'), 'AcceptInvitationPage'),
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
                  lazy: lazyRoute(() => import('@/features/dashboard/dashboard-page'), 'DashboardPage'),
                },
                {
                  path: 'profile',
                  lazy: lazyRoute(() => import('@/features/profile/profile-page'), 'ProfilePage'),
                },
                {
                  element: <RequirePermission anyOf={['servers.view', 'players.view']} />,
                  children: [
                    {
                      path: 'servers',
                      lazy: lazyRoute(() => import('@/features/servers/server-list-page'), 'ServerListPage'),
                    },
                    {
                      path: 'servers/:id',
                      lazy: lazyRoute(() => import('@/features/servers/server-detail-page'), 'ServerDetailPage'),
                    },
                    {
                      path: 'servers/:id/players',
                      lazy: lazyRoute(() => import('@/features/players/players-page'), 'PlayersPage'),
                    },
                    {
                      path: 'servers/:id/logs',
                      lazy: lazyRoute(() => import('@/features/logs/logs-page'), 'LogsPage'),
                    },
                    {
                      path: 'servers/:id/items',
                      lazy: lazyRoute(() => import('@/features/items/items-page'), 'ItemsPage'),
                    },
                    {
                      path: 'servers/:id/vehicles',
                      lazy: lazyRoute(
                        () => import('@/features/vehicles/vehicles-page'),
                        'VehiclesPage',
                      ),
                    },
                    {
                      path: 'servers/:id/chat',
                      lazy: lazyRoute(() => import('@/features/chat/chat-page'), 'ChatPage'),
                    },
                    {
                      path: 'servers/:id/map',
                      lazy: lazyRoute(() => import('@/features/map/map-page'), 'MapPage'),
                    },
                    {
                      path: 'servers/:id/events',
                      lazy: lazyRoute(() => import('@/features/events/events-page'), 'EventsPage'),
                    },
                    {
                      // Static before dynamic: weather has a page of its
                      // own, with presets and the live state, so it is
                      // listed above the generic category route.
                      path: 'servers/:id/events/weather',
                      lazy: lazyRoute(() => import('@/features/events/weather-page'), 'WeatherPage'),
                    },
                    {
                      // Also static: the thirteen climate values are a
                      // state read from the running game, not a category
                      // of events to pick from a list.
                      path: 'servers/:id/events/climate',
                      lazy: lazyRoute(() => import('@/features/events/climate-page'), 'ClimatePage'),
                    },
                    {
                      // Three entries, each directly actionable, so the
                      // generic list-and-detail page would make every one
                      // of them a two-step.
                      path: 'servers/:id/events/actions',
                      lazy: lazyRoute(() => import('@/features/events/actions-page'), 'ActionsPage'),
                    },
                    {
                      path: 'servers/:id/events/:category',
                      lazy: lazyRoute(() => import('@/features/events/category-page'), 'CategoryPage'),
                    },
                    {
                      path: 'servers/:id/console',
                      lazy: lazyRoute(() => import('@/features/console/console-page'), 'ConsolePage'),
                    },
                  ],
                },
                {
                  element: <RequirePermission anyOf={['users.manage', 'users.invite', 'settings.edit']} />,
                  children: [
                    {
                      path: 'settings',
                      lazy: lazyRoute(() => import('@/features/settings/settings-page'), 'SettingsPage'),
                    },
                    {
                      path: 'users',
                      lazy: lazyRoute(() => import('@/features/users/users-page'), 'UsersPage'),
                    },
                  ],
                },
                {
                  path: 'credits',
                  lazy: lazyRoute(() => import('@/features/panel/credits-page'), 'CreditsPage'),
                },
                {
                  path: 'health',
                  lazy: lazyRoute(() => import('@/features/dashboard/health-probe-page'), 'HealthProbePage'),
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
