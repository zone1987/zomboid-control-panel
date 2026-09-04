import { useState } from 'react'
import { Package } from 'lucide-react'

import { cn } from '@/lib/utils'
import { iconUrl, type Item } from './items'

/**
 * The game's own picture for an item, falling back to a placeholder.
 *
 * Icons are extracted from the operator's Zomboid installation, so some
 * are simply not there: a mod that ships none, or a name the packs do
 * not hold. A broken image would be worse than a box.
 */
export function ItemIcon({ item, className }: { item: Item; className?: string }) {
  const [failed, setFailed] = useState(false)

  if (item.icon === undefined || item.icon === '' || failed) {
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
      src={iconUrl(item.icon)}
      alt=""
      loading="lazy"
      decoding="async"
      // Zomboid icons are small and pixel-art; smoothing them turns
      // sharp edges to mush.
      className={cn('object-contain [image-rendering:pixelated]', className)}
      onError={() => setFailed(true)}
    />
  )
}
