import { useTranslation } from 'react-i18next'
import { Boxes, Grid2x2 } from 'lucide-react'

import { cn } from '@/lib/utils'

/**
 * Switches between the game's own flat map and an isometric render.
 *
 * Only shown when a render is actually there: offering a view nobody
 * can see is worse than not offering it.
 */
export function ProjectionToggle({
  isometric,
  onChange,
}: {
  isometric: boolean
  onChange: (isometric: boolean) => void
}) {
  const { t } = useTranslation()

  return (
    <div className="pointer-events-auto absolute bottom-3 left-1/2 z-10 flex -translate-x-1/2 rounded-md border border-border/60 bg-background/85 p-1 shadow-lg backdrop-blur">
      <button
        type="button"
        className={cn(
          'flex items-center gap-1.5 rounded-sm px-2.5 py-1 text-xs',
          isometric
            ? 'bg-primary font-medium text-primary-foreground'
            : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
        )}
        aria-pressed={isometric}
        onClick={() => onChange(true)}
      >
        <Boxes className="size-3.5" />
        {t('map.isometricView')}
      </button>

      <button
        type="button"
        className={cn(
          'flex items-center gap-1.5 rounded-sm px-2.5 py-1 text-xs',
          !isometric
            ? 'bg-primary font-medium text-primary-foreground'
            : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
        )}
        aria-pressed={!isometric}
        onClick={() => onChange(false)}
      >
        <Grid2x2 className="size-3.5" />
        {t('map.topDownView')}
      </button>
    </div>
  )
}
