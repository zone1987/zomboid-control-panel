import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { RouterProvider } from 'react-router/dom'

import { Toaster } from '@/components/ui/sonner'
import { ThemeProvider } from '@/components/theme-provider'
import { AuthProvider } from '@/features/auth/auth-context'
import { router } from '@/routes/router'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: (failureCount, error) => {
        // An expired session must surface as a redirect, not as retries.
        if (error instanceof Response && error.status === 401) {
          return false
        }

        return failureCount < 2
      },
      staleTime: 30_000,
    },
  },
})

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <AuthProvider>
          <RouterProvider router={router} />
          {/* Failures get longer than confirmations: they carry a reason
              worth reading. The close button dismisses either early. */}
          <Toaster richColors closeButton duration={6000} />
        </AuthProvider>
      </ThemeProvider>
    </QueryClientProvider>
  )
}
