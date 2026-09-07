import { useCallback, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { MapPin } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
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
import { PROJECT_ZOMBOID_MAP } from './map-config'
import { viewStateOnArrival } from './map-url-state'
import { mapOverlay, type MapPlayer } from './map'
import { ALL_LAYERS_ON, type LayerVisibility } from './layer-toggles'
import { WorldMap } from './world-map'
import { MapSearch } from './map-search'
import { MapSidebar } from './map-sidebar'

export function MapPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const [target, setTarget] = useState<WorldPoint | null>(null)
  const [visible, setVisible] = useState<LayerVisibility>(ALL_LAYERS_ON)
  const [who, setWho] = useState<string | null>(null)

  // Set once the viewer is up, so search and the place buttons can move
  // the view without the page holding viewer state of its own.
  const goTo = useRef<((point: WorldPoint, zoom?: number) => void) | null>(null)

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

  const teleport = useMutation({
    mutationFn: () =>
      teleportPlayer(id, who ?? '', { x: target?.x ?? 0, y: target?.y ?? 0, z: target?.z ?? 0 }),
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

  const move = useCallback((point: WorldPoint) => goTo.current?.(point, 80), [])

  const onPlayerClick = useCallback((player: MapPlayer) => {
    goTo.current?.({ x: player.x, y: player.y })
  }, [])

  const onReady = useCallback((move: (point: WorldPoint, zoom?: number) => void) => {
    goTo.current = move
  }, [])

  return (
    // `flex-1` against the layout's column rather than a percentage: the
    // scrolling parent only has `min-h`, so `h-full` resolves to zero,
    // and any arithmetic over header and padding sizes goes stale the
    // moment one of them changes. `min-h` keeps it usable on a short
    // window, where scrolling to the map is better than a sliver of it.
    <div className="relative min-h-[30rem] w-full flex-1">
      <WorldMap
        source={PROJECT_ZOMBOID_MAP}
        players={players}
        safehouses={overlay?.safehouses ?? []}
        vehicles={overlay?.vehicles ?? []}
        visible={visible}
        onLayerChange={(layer, shown) =>
          setVisible((previous) => ({ ...previous, [layer]: shown }))
        }
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
              {t('map.teleportBody', { x: target?.x, y: target?.y, z: target?.z ?? 0 })}
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
