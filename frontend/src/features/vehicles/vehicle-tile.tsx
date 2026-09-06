import { cn } from '@/lib/utils'
import type { VehicleRenderer } from '@/features/map/vehicle-renderer'
import { VehiclePreview } from './vehicle-preview'
import type { SpawnableVehicle } from './vehicles'

/**
 * One vehicle in the grid.
 *
 * No plus and minus: a spawn takes one vehicle, so choosing replaces the
 * choice rather than adding to a count.
 */
export function VehicleTile({
  vehicle,
  selected,
  renderer,
  onSelect,
}: {
  vehicle: SpawnableVehicle
  selected: boolean
  renderer: VehicleRenderer | null
  onSelect: () => void
}) {
  return (
    <button
      type="button"
      aria-pressed={selected}
      title={vehicle.script}
      className={cn(
        'pz-interactive flex flex-col items-center gap-1.5 rounded-md border p-2 text-center',
        selected ? 'border-primary bg-primary/10' : 'hover:bg-accent/50',
      )}
      onClick={onSelect}
    >
      <VehiclePreview script={vehicle.script} renderer={renderer} className="h-16 w-full" />

      <span className="line-clamp-2 text-xs leading-tight">{vehicle.name}</span>
    </button>
  )
}
