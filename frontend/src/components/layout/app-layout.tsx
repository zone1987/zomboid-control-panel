import { Outlet, useLocation } from 'react-router'

import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar'
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
      {/* One light field behind the whole shell, so the sidebar, the
          bars and the content read as one surface rather than three
          panels that happen to touch. */}
      <SidebarProvider className="pz-page-field">
        {/* Decorative only, so it carries no alt text and is hidden from
            assistive technology: describing scenery to a screen reader
            is noise between the reader and the page. */}
        <div className="pz-page-scene" aria-hidden="true">
          <picture>
            {/* Both formats offered by type, as the logos are: a
                browser without AVIF takes the WebP rather than nothing,
                and the img is the last fallback for either. */}
            <source srcSet="/app/brand/scene.avif" type="image/avif" />
            <source srcSet="/app/brand/scene.webp" type="image/webp" />
            <img
              src="/app/brand/scene.webp"
              alt=""
              width={1400}
              height={1332}
              loading="lazy"
              decoding="async"
            />
          </picture>
        </div>

        <AppSidebar />

        {/* Capped at the viewport so the bars stay put and only the
            middle scrolls. `position: fixed` would work too, but it
            takes the bars out of the flow and then they no longer know
            how wide the sidebar is. */}
        <SidebarInset className="h-svh overflow-hidden">
          <header className="flex h-14 shrink-0 items-center gap-2 px-4">
            {/* The one control that opens the navigation on a phone, so
                it gets a touch target rather than shadcn's 28px. */}
            <SidebarTrigger className="-ml-1 size-9 sm:size-7" />
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
          {/* `overflow-x-hidden` alongside: `overflow-y-auto` alone makes
              the x axis `auto` too, so anything a few pixels too wide
              drew a horizontal bar across the page. Content that really
              needs to scroll sideways carries its own scroller. */}
          <main className="pz-surface min-h-0 flex-1 overflow-x-hidden overflow-y-auto p-4 sm:p-6">
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

  // min-h-full rather than h-full: a child at exactly 100% height
  // overhangs the scroller's own bottom padding, so the last element sat
  // flush against the footer. pb-4 is the gap, on the child.
  return (
    // A column so a page can claim the remaining height with `flex-1`.
    // `[&>*]:w-full` because a flex column otherwise sizes children to
    // their content, and `mx-auto max-w-3xl` collapsed to the width of
    // its longest line -- visibly narrower, and changing as cards were
    // added. The width rule wins; `mx-auto` still centres within it.
    <div key={pathname} className="pz-page flex min-h-full flex-col pb-4 [&>*]:w-full">
      <Outlet />
    </div>
  )
}
