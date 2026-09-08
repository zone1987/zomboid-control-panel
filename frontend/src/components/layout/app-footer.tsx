import { CreditsDialog } from '@/features/panel/credits-dialog'
import { ConnectionLights } from '@/features/servers/connection-lights'
import { BridgeVersionLine } from './bridge-version-line'
import { PanelVersionLine } from './panel-version-line'

/**
 * The status bar under the page: what this panel is, and what it reaches.
 *
 * Both halves used to sit where they landed rather than where they
 * belong — the version and the credits notice at the bottom of the
 * sidebar, the four connection lights crowded into the top bar beside
 * the breadcrumb. A status bar is what both are, so this is where they
 * go.
 *
 * Shorter than the header on purpose: the header carries navigation and
 * controls, this carries facts, and a bar of equal height would claim
 * equal importance.
 */
export function AppFooter() {
  return (
    // Taller on a phone so the two buttons in it can be touched: a 36px
    // bar leaves room for 24px controls and no more.
    <footer className="flex h-11 shrink-0 items-center gap-2 overflow-hidden px-4 sm:h-9">
      {/* No rules between these: the gap parts them, and a line across
          a bar the page's own gradient runs behind reads as a seam. */}
      <PanelVersionLine labelled />
      <BridgeVersionLine />

      {/* The Indie Stone's terms ask for a visible notice, and the panel
          shows the game's own art throughout. The button opens it here;
          the page it links to is what the terms rest on. */}
      <CreditsDialog />

      <div className="ml-auto flex items-center gap-1">
        <ConnectionLights />
      </div>
    </footer>
  )
}

