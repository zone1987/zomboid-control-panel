import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronDown, MapPin, Search, X } from 'lucide-react'

import { cn } from '@/lib/utils'
import type { WorldPoint } from './coordinates'
import { QUICK_TARGETS } from './map-config'
import type { MapPlayer } from './map'

type Props = {
  players: MapPlayer[]
  onGoTo: (point: WorldPoint) => void
  onNotFound: () => void
}

/**
 * Search along the top, quick targets along the bottom.
 *
 * Both float over the map so the map itself can use the whole frame,
 * and they are kept apart so the search has room to grow with the
 * window rather than sharing a row with six buttons.
 */
export function MapSearch({ players, onGoTo, onNotFound }: Props) {
  const { t } = useTranslation()
  const [needle, setNeedle] = useState('')
  const [open, setOpen] = useState(false)
  const [placesOpen, setPlacesOpen] = useState(false)

  const term = needle.trim().toLowerCase()

  const matches =
    term === ''
      ? []
      : players.filter((player) => player.username.toLowerCase().includes(term)).slice(0, 6)

  const submit = () => {
    const coordinates = parsePoint(needle)

    if (coordinates !== null) {
      onGoTo(coordinates)
      setOpen(false)

      return
    }

    if (matches.length > 0) {
      onGoTo({ x: matches[0].x, y: matches[0].y })
      setOpen(false)

      return
    }

    onNotFound()
  }

  return (
    <>
      <div className="pointer-events-auto absolute left-14 right-3 top-3 z-10 max-w-xl sm:right-auto sm:w-96">
        <div className="flex h-9 items-center rounded-md border border-border/60 bg-background/85 shadow-lg backdrop-blur">
          <Search className="pointer-events-none ml-2.5 size-4 shrink-0 text-muted-foreground" />
          <input
            value={needle}
            className="h-full min-w-0 flex-1 bg-transparent px-2 text-sm outline-none placeholder:text-muted-foreground"
            placeholder={t('map.search')}
            aria-label={t('map.search')}
            onFocus={() => setOpen(true)}
            onChange={(event) => {
              setNeedle(event.target.value)
              setOpen(true)
            }}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                submit()
              } else if (event.key === 'Escape') {
                setOpen(false)
              }
            }}
          />

          {needle !== '' && (
            <button
              type="button"
              className="mr-2 text-muted-foreground hover:text-foreground"
              aria-label={t('common.cancel')}
              onClick={() => {
                setNeedle('')
                setOpen(false)
              }}
            >
              <X className="size-4" />
            </button>
          )}
        </div>

        {open && matches.length > 0 && (
          <ul className="mt-1 overflow-hidden rounded-md border border-border/60 bg-background/95 shadow-lg backdrop-blur">
            {matches.map((player) => (
              <li key={player.username}>
                <button
                  type="button"
                  className="flex w-full items-center gap-2 px-2.5 py-1.5 text-left text-sm hover:bg-accent hover:text-accent-foreground"
                  onClick={() => {
                    onGoTo({ x: player.x, y: player.y })
                    setOpen(false)
                  }}
                >
                  <span
                    aria-hidden
                    className={cn(
                      'size-2 shrink-0 rounded-full',
                      player.infected ? 'bg-destructive' : 'bg-emerald-500',
                    )}
                  />
                  <span className="min-w-0 flex-1 truncate">{player.username}</span>
                  <span className="shrink-0 text-xs tabular-nums text-muted-foreground">
                    {Math.round(player.x)}, {Math.round(player.y)}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      {/* Bottom right, folded away until wanted: six place names are a
          lot of furniture for something used now and then. */}
      <div className="pointer-events-auto absolute bottom-3 right-3 z-10 flex flex-col items-end gap-1">
        {placesOpen && (
          <div className="flex flex-col items-stretch gap-0.5 rounded-md border border-border/60 bg-background/85 p-1 shadow-lg backdrop-blur">
            {QUICK_TARGETS.map((place) => (
              <button
                key={place.id}
                type="button"
                className="flex items-center gap-1.5 rounded-sm px-2 py-1 text-xs text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                onClick={() => onGoTo({ x: place.x, y: place.y })}
              >
                <MapPin className="size-3 shrink-0" />
                {t(`players.landmarks.${place.id}`)}
              </button>
            ))}
          </div>
        )}

        <button
          type="button"
          className="flex items-center gap-1.5 rounded-md border border-border/60 bg-background/85 px-2.5 py-1.5 text-xs shadow-lg backdrop-blur hover:bg-accent hover:text-accent-foreground"
          title={t('map.places')}
          aria-label={t('map.places')}
          aria-expanded={placesOpen}
          onClick={() => setPlacesOpen((open) => !open)}
        >
          <MapPin className="size-3.5" />
          {t('map.places')}
          <ChevronDown
            className={cn('size-3.5 transition-transform', placesOpen && 'rotate-180')}
          />
        </button>
      </div>
    </>
  )
}

/** Accepts "10778,9770", "10778 9770" and "10778x9770". */
function parsePoint(needle: string): WorldPoint | null {
  const match = needle.trim().match(/^(\d{1,5})\s*[,x\s]\s*(\d{1,5})$/)

  if (match === null) {
    return null
  }

  return { x: Number.parseInt(match[1], 10), y: Number.parseInt(match[2], 10) }
}
