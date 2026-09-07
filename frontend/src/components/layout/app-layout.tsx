import { Outlet, useLocation } from 'react-router'

import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar'
import { Separator } from '@/components/ui/separator'
import { ActiveServerProvider } from '@/features/servers/active-server'
import { AppSidebar } from './app-sidebar'
import { AppFooter } from './app-footer'
import { Breadcrumbs } from './breadcrumbs'
import { AppUpdateBanner } from './app-update-banner'
import { LanguageToggle } from './language-toggle'
import { ThemeToggle } from './theme-toggle'

export function AppLayout() {
  return (
    <ActiveServerProvider>
      <SidebarProvider>
        <AppSidebar />

        {/* Capped at the viewport so the bars stay put and only the
            middle scrolls. `position: fixed` would work too, but it
            takes the bars out of the flow and then they no longer know
            how wide the sidebar is. */}
        <SidebarInset className="h-svh overflow-hidden">
          <header className="flex h-14 shrink-0 items-center gap-2 border-b bg-background px-4">
            <SidebarTrigger className="-ml-1" />
            <Separator orientation="vertical" className="mr-2 h-4" />
            <Breadcrumbs />

            <div className="ml-auto flex items-center gap-1">
              <AppUpdateBanner />
              <LanguageToggle />
              <ThemeToggle />
            </div>
          </header>

          {/* min-h-0 so a page that wants the full height can have it:
              without it flex-1 grows past the viewport instead. The
              scrolling lives here rather than on the page, which is
              what keeps the header and footer still. */}
          <main className="pz-surface min-h-0 flex-1 overflow-y-auto p-6">
            <PageTransition />
          </main>

          <AppFooter />
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
