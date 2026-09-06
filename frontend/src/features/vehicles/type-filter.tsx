import { useTranslation } from 'react-i18next'

import { cn } from '@/lib/utils'
import type { VehicleType } from './vehicles'

/**
 * Which kinds of vehicle to show.
 *
 * Chips rather than a dropdown: there are nine at most, several can be
 * on at once, and which are active has to be visible without opening
 * anything. Nothing selected means every type, so the filter starts out
 * of the way.
 */
export function TypeFilter({
  available,
  active,
  counts,
  onToggle,
  onClear,
}: {
  available: VehicleType[]
  active: string[]
  counts: Record<string, number>
  onToggle: (type: VehicleType) => void
  onClear: () => void
}) {
  const { t } = useTranslation()

  if (available.length < 2) {
    return null
  }

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <button
        type="button"
        aria-pressed={active.length === 0}
        className={cn(
          'pz-interactive rounded-full border px-2.5 py-1 text-xs',
          active.length === 0
            ? 'border-primary bg-primary/10 text-foreground'
            : 'text-muted-foreground hover:bg-accent',
        )}
        onClick={onClear}
      >
        {t('vehicles.allTypes')}
      </button>

      {available.map((type) => {
        const on = active.includes(type)

        return (
          <button
            key={type}
            type="button"
            aria-pressed={on}
            className={cn(
              'pz-interactive rounded-full border px-2.5 py-1 text-xs',
              on
                ? 'border-primary bg-primary/10 text-foreground'
                : 'text-muted-foreground hover:bg-accent',
            )}
            onClick={() => onToggle(type)}
          >
            {t(`vehicles.types.${type}`)}
            <span className="ml-1.5 font-mono text-[0.65rem] opacity-60">{counts[type]}</span>
          </button>
        )
      })}
    </div>
  )
}
