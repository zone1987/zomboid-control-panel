import { useTranslation } from 'react-i18next'
import { Car, Home, Type, Users } from 'lucide-react'

import { cn } from '@/lib/utils'
import { useAuth } from '@/features/auth/auth-context'
import type { Permission } from '@/features/auth/types'

export type MapLayerId = 'players' | 'safehouses' | 'vehicles' | 'places'

export type LayerVisibility = Record<MapLayerId, boolean>

/**
 * Everything the map can draw, with what it takes to draw it.
 *
 * A layer whose data comes from the server needs the permission that
 * covers it; the place names are the panel's own and need none.
 */
export const MAP_LAYERS: { id: MapLayerId; icon: typeof Users; needs?: Permission }[] = [
  { id: 'players', icon: Users, needs: 'players.view' },
  { id: 'safehouses', icon: Home, needs: 'players.view' },
  { id: 'vehicles', icon: Car, needs: 'vehicles.view' },
  { id: 'places', icon: Type },
]

export const ALL_LAYERS_ON: LayerVisibility = {
  players: true,
  safehouses: true,
  vehicles: true,
  places: true,
}

/**
 * Which layers the map draws, floating over its left edge.
 *
 * Opposite the floor control, and the same shape: an operator watching
 * players does not want fifty parked cars in the way, and one hunting
 * for a vehicle wants exactly that.
 */
export function LayerToggles({
  visible,
  counts,
  onChange,
}: {
  visible: LayerVisibility
  counts: Record<MapLayerId, number>
  onChange: (layer: MapLayerId, shown: boolean) => void
}) {
  const { t } = useTranslation()
  // Read here rather than passed down: every caller would only be
  // forwarding it from the same place.
  const { can } = useAuth()

  return (
    <div
      className="pointer-events-auto absolute left-3 top-1/2 z-10 flex -translate-y-1/2 flex-col gap-0.5 rounded-md border border-border/60 bg-background/85 p-1 shadow-lg backdrop-blur"
      role="group"
      aria-label={t('map.layers')}
    >
      {MAP_LAYERS.map((layer) => {
        const allowed = layer.needs === undefined || can(layer.needs)
        const shown = allowed && visible[layer.id]
        const label = t(`map.layer.${layer.id}`)
        // Kept visible but disabled, and it says why: a control that
        // vanished would look like a fault rather than a restriction.
        const title = allowed ? label : `${label} — ${t(`map.layerForbidden.${layer.id}`)}`

        return (
          <button
            key={layer.id}
            type="button"
            disabled={!allowed}
            className={cn(
              'flex items-center gap-2 rounded-sm px-2 py-1.5 text-xs',
              // Green rather than the accent colour: this says "on", not
              // "selected", and the panel's accent is already the colour
              // of the active floor.
              shown && 'bg-emerald-500/15 text-foreground',
              !shown && allowed && 'text-muted-foreground/60 hover:bg-accent hover:text-accent-foreground',
              !allowed && 'cursor-not-allowed text-muted-foreground/40',
            )}
            title={title}
            aria-label={title}
            aria-pressed={shown}
            onClick={() => onChange(layer.id, !shown)}
          >
            <layer.icon className={cn('size-4 shrink-0', shown && 'text-emerald-500')} />
            <span className="min-w-4 tabular-nums">{allowed ? counts[layer.id] : '—'}</span>
          </button>
        )
      })}
    </div>
  )
}
