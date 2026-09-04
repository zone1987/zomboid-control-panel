import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Minus, Plus } from 'lucide-react'

type Props = {
  levels: number[]
  floor: number
  onChange: (floor: number) => void
  /** The element the keyboard and wheel shortcuts listen on. */
  target: HTMLElement | null
}

/**
 * The floor picker, floating over the map.
 *
 * Up, the floor, down -- the shape the game's own map uses, and small
 * enough not to cover what is underneath. A source with a single floor
 * hides it rather than showing a button that does nothing.
 */
export function FloorControl({ levels, floor, onChange, target }: Props) {
  const { t } = useTranslation()

  const highest = Math.max(...levels, 0)
  const lowest = Math.min(...levels, 0)

  useEffect(() => {
    if (target === null || levels.length < 2) {
      return
    }

    const step = (direction: number) => {
      const next = floor + direction

      if (levels.includes(next)) {
        onChange(next)
      }
    }

    const onKeyDown = (event: KeyboardEvent) => {
      // Comma and full stop, the way the reference map does it. Ignored
      // while typing, so the search box keeps its punctuation.
      if (event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement) {
        return
      }

      if (event.key === ',') {
        event.preventDefault()
        step(-1)
      } else if (event.key === '.') {
        event.preventDefault()
        step(1)
      }
    }

    const onWheel = (event: WheelEvent) => {
      // Shift changes floor; the plain wheel stays with the zoom.
      if (!event.shiftKey) {
        return
      }

      event.preventDefault()
      event.stopPropagation()
      step(event.deltaY < 0 ? 1 : -1)
    }

    window.addEventListener('keydown', onKeyDown)
    target.addEventListener('wheel', onWheel, { passive: false, capture: true })

    return () => {
      window.removeEventListener('keydown', onKeyDown)
      target.removeEventListener('wheel', onWheel, { capture: true })
    }
  }, [target, levels, floor, onChange])

  if (levels.length < 2) {
    return null
  }

  return (
    <div
      className="pointer-events-auto absolute right-3 top-1/2 z-10 flex -translate-y-1/2 flex-col items-center rounded-md border border-border/60 bg-background/85 shadow-lg backdrop-blur"
      role="group"
      aria-label={t('map.floors')}
    >
      <button
        type="button"
        className="flex size-8 items-center justify-center rounded-t-md text-muted-foreground hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-40"
        disabled={floor >= highest}
        title={t('map.floorUp')}
        aria-label={t('map.floorUp')}
        onClick={() => onChange(floor + 1)}
      >
        <Plus className="size-4" />
      </button>

      {/* The floor itself, shown the way the game does: G for ground. */}
      <span
        className="flex h-8 w-8 items-center justify-center text-sm font-medium tabular-nums text-primary"
        title={t(floor === 0 ? 'map.groundFloor' : 'map.floorNumber', { floor })}
        aria-live="polite"
      >
        {floor === 0 ? 'G' : floor}
      </span>

      <button
        type="button"
        className="flex size-8 items-center justify-center rounded-b-md text-muted-foreground hover:bg-accent hover:text-accent-foreground disabled:pointer-events-none disabled:opacity-40"
        disabled={floor <= lowest}
        title={t('map.floorDown')}
        aria-label={t('map.floorDown')}
        onClick={() => onChange(floor - 1)}
      >
        <Minus className="size-4" />
      </button>
    </div>
  )
}
