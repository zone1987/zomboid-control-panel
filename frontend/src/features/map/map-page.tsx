import { useCallback, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { MapPin } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { teleportPlayer } from '@/features/players/players'
import type { WorldPoint } from './coordinates'
import { GAME_MAP_SOURCE, isometricSourceFrom, type MapSource } from './map-config'
import { viewStateOnArrival } from './map-url-state'
import { mapOverlay, mapStatus, type MapPlayer } from './map'
import { WorldMap } from './world-map'
import { MapSearch } from './map-search'
import { MapSidebar } from './map-sidebar'
import { ProjectionToggle } from './projection-toggle'

export function MapPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const [target, setTarget] = useState<WorldPoint | null>(null)
  const [who, setWho] = useState<string | null>(null)

  // Set once the viewer is up, so search and the place buttons can move
  // the view without the page holding viewer state of its own.
  const goTo = useRef<((point: WorldPoint, zoom?: number) => void) | null>(null)

  const { data: status, isPending } = useQuery({
    queryKey: ['map-status'],
    queryFn: mapStatus,
    staleTime: Number.POSITIVE_INFINITY,
  })

  const { data: overlay } = useQuery({
    queryKey: ['map-overlay', id],
    queryFn: () => mapOverlay(id),
    retry: false,
    refetchInterval: 3_000,
    refetchIntervalInBackground: true,
    placeholderData: (previous) => previous,
  })

  const players = overlay?.players ?? []

  // Captured when the module loaded, not read here: this page is loaded
  // lazily, and by the time it renders the viewer has already written
  // its own position into the hash.
  const initial = useMemo(() => viewStateOnArrival(), [])

  // An isometric render when the operator has one, the game's own map
  // otherwise. Switching rebuilds the viewer, which is right: they are
  // different images, not two views of one.
  const isometric = useMemo(
    () =>
      status?.isometric.available === true
        ? isometricSourceFrom(status.isometric.levels, status.isometric.geometry)
        : null,
    [status],
  )

  const [preferIsometric, setPreferIsometric] = useState(true)
  const source: MapSource = preferIsometric && isometric !== null ? isometric : GAME_MAP_SOURCE

  const teleport = useMutation({
    mutationFn: () =>
      teleportPlayer(id, who ?? '', { x: target?.x ?? 0, y: target?.y ?? 0, z: 0 }),
    onSuccess: () => {
      setTarget(null)
      setWho(null)
      toast.success(t('players.teleported'))
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError && error.status === 502
          ? t('map.rconFailed')
          : t('errors.generic'),
      ),
  })

  const move = useCallback((point: WorldPoint) => goTo.current?.(point, 6), [])

  const onPlayerClick = useCallback((player: MapPlayer) => {
    goTo.current?.({ x: player.x, y: player.y })
  }, [])

  const onReady = useCallback((move: (point: WorldPoint, zoom?: number) => void) => {
    goTo.current = move
  }, [])

  if (isPending) {
    return <Skeleton className="h-[36rem] w-full" />
  }

  if (status?.available !== true) {
    return (
      <Alert>
        <AlertTitle>{t('map.noTiles')}</AlertTitle>
        <AlertDescription>
          <p>{t('map.noTilesHint')}</p>
          <code className="mt-1 block text-xs">
            php bin/console app:map:import &lt;pfad&gt;/media/maps
          </code>
        </AlertDescription>
      </Alert>
    )
  }

  return (
    // Fills whatever the layout leaves, rather than guessing the header
    // height and leaving a strip along the bottom.
    <div className="relative h-full min-h-[30rem] w-full">
      <WorldMap
        source={source}
        players={players}
        safehouses={overlay?.safehouses ?? []}
        initial={initial}
        onContextMenu={setTarget}
        onPlayerClick={onPlayerClick}
        onReady={onReady}
      />

      <MapSearch
        players={players}
        onGoTo={move}
        onNotFound={() => toast.error(t('map.notFound'))}
      />

      {isometric !== null && (
        <ProjectionToggle
          isometric={preferIsometric}
          onChange={setPreferIsometric}
        />
      )}

      <MapSidebar
        players={players}
        safehouses={overlay?.safehouses ?? []}
        onGoTo={move}
      />

      <Dialog open={target !== null} onOpenChange={(open) => !open && setTarget(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('map.teleportTitle')}</DialogTitle>
            <DialogDescription>
              {t('map.teleportBody', { x: target?.x, y: target?.y })}
            </DialogDescription>
          </DialogHeader>

          {players.length === 0 ? (
            <p className="rounded-md border p-3 text-sm text-muted-foreground">
              {t('map.noPlayersToMove')}
            </p>
          ) : (
            <Select value={who ?? undefined} onValueChange={setWho}>
              <SelectTrigger className="w-full">
                <SelectValue placeholder={t('map.choosePlayer')} />
              </SelectTrigger>
              <SelectContent>
                {players.map((player) => (
                  <SelectItem key={player.username} value={player.username}>
                    {player.username}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}

          <DialogFooter>
            <Button variant="ghost" onClick={() => setTarget(null)}>
              {t('common.cancel')}
            </Button>
            <Button disabled={who === null || teleport.isPending} onClick={() => teleport.mutate()}>
              <MapPin className="size-4" />
              {teleport.isPending ? t('common.loading') : t('players.teleport')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
