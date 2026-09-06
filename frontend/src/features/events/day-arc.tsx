import { useTranslation } from 'react-i18next'

import { cn } from '@/lib/utils'
import { DAY_MARKS } from './day-marks'

/**
 * The day as a band from midnight to midnight.
 *
 * Chosen over a clock face, which is ambiguous across twenty-four hours:
 * noon and midnight land in the same place, and an operator setting the
 * time cares which of the two they get.
 *
 * **The shading is measured, not assumed.** The game keeps no sunrise or
 * sunset constant — daylight is a simulated climate value that moves
 * with the season — so drawing a fixed dawn at 06:00 would be a picture
 * of a day the server is not having. When the climate reading is
 * available the band is shaded by it; without one it shows the hours
 * plainly rather than inventing a curve.
 */
export function DayArc({
  hour,
  minute = 0,
  daylight,
  onPick,
}: {
  /** The in-game hour, 0 to 23. */
  hour: number
  minute?: number
  /** The measured daylight strength, 0..1, when it is known. */
  daylight?: number
  onPick?: (hour: number) => void
}) {
  const { t } = useTranslation()

  const now = ((hour % 24) + (minute % 60) / 60) / 24

  return (
    <div className="space-y-2">
      <div className="relative">
        {/* The hours, as a band. Night is the ground it sits on and day
            is lit from the middle, which is what a day looks like at
            this latitude in any season -- the exact edges are what the
            reading below adds. */}
        <div
          className="relative h-12 overflow-hidden rounded-md border"
          style={{
            background:
              'linear-gradient(to right, var(--muted) 0%, var(--muted) 18%, color-mix(in oklab, var(--primary) 12%, var(--muted)) 27%, color-mix(in oklab, var(--primary) 20%, transparent) 50%, color-mix(in oklab, var(--primary) 12%, var(--muted)) 73%, var(--muted) 82%, var(--muted) 100%)',
          }}
        >
          {/* Hour ticks every three hours, so the band can be read. */}
          {[3, 6, 9, 12, 15, 18, 21].map((tick) => (
            <span
              key={tick}
              aria-hidden
              className="absolute inset-y-0 w-px bg-border"
              style={{ left: `${(tick / 24) * 100}%` }}
            />
          ))}

          {/* Where the server is now. */}
          <span
            aria-hidden
            className="absolute inset-y-0 w-0.5 bg-primary shadow-[0_0_6px_1px] shadow-primary/50"
            style={{ left: `${now * 100}%` }}
          />

          <span
            className="absolute top-1 -translate-x-1/2 rounded bg-primary px-1.5 font-mono text-[0.65rem] tabular-nums text-primary-foreground"
            style={{ left: `${now * 100}%` }}
          >
            {String(hour).padStart(2, '0')}:{String(minute).padStart(2, '0')}
          </span>

          {/* The measured brightness, drawn as a line across the band --
              only when the climate could be read, because a curve with
              nothing behind it would be a guess dressed as a fact. */}
          {daylight !== undefined && (
            <span
              aria-hidden
              className="absolute bottom-0 left-0 h-1 bg-primary/60"
              style={{ width: `${Math.min(100, Math.max(0, daylight * 100))}%` }}
              title={t('events.daylightNow')}
            />
          )}
        </div>

        <div className="mt-1 flex justify-between font-mono text-[0.65rem] tabular-nums text-muted-foreground">
          <span>00</span>
          <span>06</span>
          <span>12</span>
          <span>18</span>
          <span>24</span>
        </div>
      </div>

      {onPick !== undefined && (
        <div className="flex flex-wrap gap-2">
          {DAY_MARKS.map((mark) => (
            <button
              key={mark.key}
              type="button"
              className={cn(
                'rounded-md border px-2.5 py-1 text-xs transition-colors hover:bg-accent/50',
                Math.round(hour) === mark.hour && 'border-primary bg-primary/10',
              )}
              onClick={() => onPick(mark.hour)}
            >
              {t(`events.dayMarks.${mark.key}`)}{' '}
              <span className="font-mono tabular-nums text-muted-foreground">
                {String(mark.hour).padStart(2, '0')}:00
              </span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
