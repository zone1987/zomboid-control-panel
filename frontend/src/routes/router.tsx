import { createBrowserRouter } from 'react-router'

import { AppLayout } from '@/components/layout/app-layout'
import { DashboardPage } from '@/features/dashboard/dashboard-page'
import { HealthProbePage } from '@/features/dashboard/health-probe-page'

export const router = createBrowserRouter(
  [
    {
      path: '/',
      element: <AppLayout />,
      children: [
        { index: true, element: <DashboardPage /> },
        { path: 'health', element: <HealthProbePage /> },
      ],
    },
  ],
  { basename: '/app' },
)
