import { useState } from 'react'
import { Package } from 'lucide-react'

import { cn } from '@/lib/utils'
import { hasIcon, iconUrl, type Item } from './items'

/**
 * The game's own picture for an item, falling back to a placeholder.
 *
 * Icons are extracted from the operator's Zomboid installation, so some
 * are simply not there: a mod that ships none, or a name the packs do
 * not hold. A broken image would be worse than a box.
 */
export function ItemIcon({ item, className }: { item: Item; className?: string }) {
  const [failed, setFailed] = useState(false)

  if (!hasIcon(item) || failed) {
    return (
      <span
        className={cn(
          'flex items-center justify-center rounded bg-muted text-muted-foreground',
          className,
        )}
      >
        <Package className="size-1/2" />
      </span>
    )
  }

  return (
    <img
      src={iconUrl(item.icon as string)}
      alt=""
      // Zomboid icons are at most 32 pixels; giving the browser the size
      // up front stops the row jumping as pictures arrive.
      width={32}
      height={32}
      loading="lazy"
      decoding="async"
      // Sharp pixel art: smoothing 32 pixels turns edges to mush.
      className={cn('object-contain [image-rendering:pixelated]', className)}
      onError={() => setFailed(true)}
    />
  )
}
