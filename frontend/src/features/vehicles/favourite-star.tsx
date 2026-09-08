import { useTranslation } from 'react-i18next'
import { Star } from 'lucide-react'

import { cn } from '@/lib/utils'

/** The star sits over a tile, so it is its own control beside the tile's button. */
export function FavouriteStar({
  marked,
  onToggle,
  className,
}: {
  marked: boolean
  onToggle: () => void
  className?: string
}) {
  const { t } = useTranslation()
  const label = marked ? t('vehicles.removeFavourite') : t('vehicles.addFavourite')

  return (
    <button
      type="button"
      aria-pressed={marked}
      aria-label={label}
      title={label}
      className={cn(
        // p-2 on a phone: p-1 around a 14px icon leaves a 22px target,
        // under the 32px a finger reliably hits.
        'pz-interactive absolute right-1 top-1 rounded-sm p-2.5 sm:p-1',
        marked ? 'text-primary' : 'text-muted-foreground/40 hover:bg-accent hover:text-foreground',
        className,
      )}
      onClick={onToggle}
    >
      <Star className={cn('size-3.5', marked && 'fill-current')} />
    </button>
  )
}
