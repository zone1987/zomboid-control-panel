import { Outlet } from 'react-router'

import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar'
import { Separator } from '@/components/ui/separator'
import { ActiveServerProvider } from '@/features/servers/active-server'
import { AppSidebar } from './app-sidebar'
import { Breadcrumbs } from './breadcrumbs'

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
          </header>

          {/* min-h-0 so a page that wants the full height can have it:
              without it flex-1 grows past the viewport instead. */}
          <main className="min-h-0 flex-1 p-6">
            <Outlet />
          </main>
        </SidebarInset>
      </SidebarProvider>
    </ActiveServerProvider>
  )
}
