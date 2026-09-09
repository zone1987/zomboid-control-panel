import { useState } from 'react'
import { Package } from 'lucide-react'

import { cn } from '@/lib/utils'
import type { Mod } from './mods'

/**
 * A mod's cover, with something to show when there is none.
 *
 * Steam serves these from its own CDN, so a missing or blocked image is
 * ordinary rather than exceptional — a broken-image glyph in a grid of
 * forty would read as the page being broken.
 */
export function ModCover({ mod, className }: { mod: Mod; className?: string }) {
  const [failed, setFailed] = useState(false)

  if (mod.previewUrl === null || failed) {
    return (
      <div
        className={cn(
          'flex items-center justify-center rounded-sm bg-muted text-muted-foreground',
          className,
        )}
      >
        <Package className="size-6" />
      </div>
    )
  }

  return (
    <img
      src={mod.previewUrl}
      alt=""
      loading="lazy"
      decoding="async"
      onError={() => setFailed(true)}
      className={cn('rounded-sm bg-muted object-cover', className)}
    />
  )
}
