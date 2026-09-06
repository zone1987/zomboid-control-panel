import { cn } from '@/lib/utils'
import type { VehicleRenderer } from '@/features/map/vehicle-renderer'
import { FavouriteStar } from './favourite-star'
import { VehiclePreview } from './vehicle-preview'

/** One body shell, pictured by whichever of its members has artwork. */
export function BodyTile({
  name,
  count,
  preview,
  selected,
  favourite,
  renderer,
  onSelect,
  onToggleFavourite,
}: {
  name: string
  count: number
  /** A member with artwork; null when none of them has any. */
  preview: string | null
  selected: boolean
  favourite: boolean
  renderer: VehicleRenderer | null
  onSelect: () => void
  onToggleFavourite: () => void
}) {
  return (
    <div className="relative h-full">
      <button
        type="button"
        aria-pressed={selected}
        className={cn(
          'pz-interactive flex h-full w-full flex-col items-center gap-1 rounded-md border p-2 text-center',
          selected ? 'border-primary bg-primary/10' : 'hover:bg-accent/50',
        )}
        onClick={onSelect}
      >
        <VehiclePreview
          script={preview ?? ''}
          renderer={preview === null ? null : renderer}
          className="h-12 w-full"
        />

        <span className="line-clamp-2 text-xs font-medium leading-tight">{name}</span>
        <span className="mt-auto font-mono text-[0.65rem] text-muted-foreground">{count}</span>
      </button>

      <FavouriteStar marked={favourite} onToggle={onToggleFavourite} />
    </div>
  )
}
