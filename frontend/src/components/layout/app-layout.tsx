import { Outlet, useLocation } from 'react-router'

import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar'
import { Separator } from '@/components/ui/separator'
import { ActiveServerProvider } from '@/features/servers/active-server'
import { ConnectionLights } from '@/features/servers/connection-lights'
import { AppSidebar } from './app-sidebar'
import { Breadcrumbs } from './breadcrumbs'
import { AppUpdateBanner } from './app-update-banner'
import { LanguageToggle } from './language-toggle'
import { ThemeToggle } from './theme-toggle'

export function AppLayout() {
  return (
    <ActiveServerProvider>
      <SidebarProvider>
        <AppSidebar />

        <SidebarInset>
          <header className="flex h-14 shrink-0 items-center gap-2 border-b px-4">
            <SidebarTrigger className="-ml-1" />
            <Separator orientation="vertical" className="mr-2 h-4" />
            <Breadcrumbs />

            <div className="ml-auto flex items-center gap-1">
              <ConnectionLights />
              <Separator orientation="vertical" className="mx-1 h-4" />
              <AppUpdateBanner />
              <LanguageToggle />
              <ThemeToggle />
            </div>
          </header>

          {/* min-h-0 so a page that wants the full height can have it:
              without it flex-1 grows past the viewport instead. */}
          <main className="pz-surface min-h-0 flex-1 p-6">
            <PageTransition />
          </main>
        </SidebarInset>
      </SidebarProvider>
    </ActiveServerProvider>
  )
}

/**
 * Replays the entry animation on every navigation.
 *
 * Keyed by pathname because an animation only runs when the element is
 * new; without the key a route change would reuse the node and play
 * nothing.
 */
function PageTransition() {
  const { pathname } = useLocation()

  return (
    <div key={pathname} className="pz-page h-full">
      <Outlet />
    </div>
  )
}
