import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronRight, Home, Users } from 'lucide-react'

import { cn } from '@/lib/utils'
import { Badge } from '@/components/ui/badge'
import type { WorldPoint } from './coordinates'
import type { MapPlayer, MapSafehouse } from './map'

type Props = {
  players: MapPlayer[]
  safehouses: MapSafehouse[]
  onGoTo: (point: WorldPoint) => void
}

/**
 * Players and safehouses, floating over the right edge of the map.
 *
 * Collapsible, because on a narrow screen the map matters more than the
 * list -- and because an operator watching one player does not need the
 * panel open the whole time.
 */
export function MapSidebar({ players, safehouses, onGoTo }: Props) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(true)

  if (!open) {
    return (
      <button
        type="button"
        className="pointer-events-auto absolute right-3 top-3 z-10 flex items-center gap-1.5 rounded-md border border-border/60 bg-background/85 px-2.5 py-1.5 text-xs shadow-lg backdrop-blur hover:bg-accent hover:text-accent-foreground"
        title={t('map.showPanel')}
        aria-label={t('map.showPanel')}
        onClick={() => setOpen(true)}
      >
        <Users className="size-3.5" />
        {players.length}
      </button>
    )
  }

  return (
    // Flush with the right edge, and only as tall as its contents: a
    // container reaching to the bottom would swallow clicks meant for
    // the floor control and the places button underneath it.
    <div className="pointer-events-auto absolute right-3 top-3 z-10 flex max-h-[60%] w-56 flex-col gap-2 overflow-hidden">
      <section className="flex min-h-0 flex-col overflow-hidden rounded-md border border-border/60 bg-background/85 shadow-lg backdrop-blur">
        <header className="flex shrink-0 items-center gap-2 border-b border-border/60 px-2.5 py-1.5">
          <Users className="size-3.5 text-muted-foreground" />
          <h2 className="flex-1 text-xs font-medium">{t('map.players')}</h2>
          <Badge variant="secondary" className="h-5 px-1.5 text-[10px]">
            {players.length}
          </Badge>

          <button
            type="button"
            className="text-muted-foreground hover:text-foreground"
            title={t('map.hidePanel')}
            aria-label={t('map.hidePanel')}
            onClick={() => setOpen(false)}
          >
            <ChevronRight className="size-3.5" />
          </button>
        </header>

        <div className="min-h-0 flex-1 overflow-y-auto p-1">
          {players.length === 0 ? (
            <p className="p-2 text-xs text-muted-foreground">{t('map.noPlayers')}</p>
          ) : (
            players.map((player) => (
              <button
                key={player.username}
                type="button"
                className="flex w-full items-center gap-2 rounded-sm px-2 py-1 text-left text-xs hover:bg-accent hover:text-accent-foreground"
                onClick={() => onGoTo({ x: player.x, y: player.y })}
              >
                <span
                  aria-hidden
                  className={cn(
                    'size-2 shrink-0 rounded-full',
                    player.infected ? 'bg-destructive' : 'bg-emerald-500',
                  )}
                />
                <span className="min-w-0 flex-1 truncate">{player.username}</span>
                <span className="shrink-0 tabular-nums text-muted-foreground">
                  {Math.round(player.x)},{Math.round(player.y)}
                </span>
              </button>
            ))
          )}
        </div>
      </section>

      <section className="flex max-h-48 min-h-0 flex-col overflow-hidden rounded-md border border-border/60 bg-background/85 shadow-lg backdrop-blur">
        <header className="flex shrink-0 items-center gap-2 border-b border-border/60 px-2.5 py-1.5">
          <Home className="size-3.5 text-muted-foreground" />
          <h2 className="flex-1 text-xs font-medium">{t('map.safehouses')}</h2>
          <Badge variant="secondary" className="h-5 px-1.5 text-[10px]">
            {safehouses.length}
          </Badge>
        </header>

        <div className="min-h-0 flex-1 overflow-y-auto p-1">
          {safehouses.length === 0 ? (
            <p className="p-2 text-xs text-muted-foreground">{t('map.noSafehouses')}</p>
          ) : (
            safehouses.map((house, index) => (
              <button
                key={`${house.x}-${house.y}-${index}`}
                type="button"
                className="flex w-full flex-col rounded-sm px-2 py-1 text-left text-xs hover:bg-accent hover:text-accent-foreground"
                onClick={() => onGoTo({ x: house.x, y: house.y })}
              >
                <span className="truncate">{house.title === '' ? house.owner : house.title}</span>
                <span className="text-[10px] text-muted-foreground">
                  {t('map.membersCount', { count: house.members.length })}
                </span>
              </button>
            ))
          )}
        </div>
      </section>
    </div>
  )
}
