import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Crosshair, Home, MapPin, Search, Users } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
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
import { getServer } from '@/features/servers/servers'
import { teleportPlayer } from '@/features/players/players'
import { WorldMap } from './world-map'
import { mapOverlay, mapStatus, parseCoordinates, PLACES } from './map'

export function MapPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const [needle, setNeedle] = useState('')
  const [focus, setFocus] = useState<{ x: number; y: number } | null>(null)
  const [target, setTarget] = useState<{ x: number; y: number } | null>(null)
  const [who, setWho] = useState<string | null>(null)

  const { data: server } = useQuery({ queryKey: ['server', id], queryFn: () => getServer(id) })

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

  const jump = () => {
    const point = parseCoordinates(needle)

    if (point !== null) {
      setFocus(point)

      return
    }

    const player = players.find((entry) =>
      entry.username.toLowerCase().includes(needle.trim().toLowerCase()),
    )

    if (player !== undefined) {
      setFocus({ x: player.x, y: player.y })

      return
    }

    toast.error(t('map.notFound'))
  }

  if (isPending) {
    return <Skeleton className="h-[36rem] w-full" />
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold">{t('map.title')}</h1>
        <p className="text-muted-foreground">
          {server ? t('map.descriptionFor', { server: server.name }) : t('map.description')}
        </p>
      </div>

      {status?.available !== true ? (
        <Alert>
          <AlertTitle>{t('map.noTiles')}</AlertTitle>
          <AlertDescription>
            <p>{t('map.noTilesHint')}</p>
            <code className="mt-1 block text-xs">
              php bin/console app:map:import &lt;pfad&gt;/media/maps
            </code>
          </AlertDescription>
        </Alert>
      ) : (
        <div className="grid gap-4 lg:grid-cols-[1fr_16rem]">
          <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
              <div className="relative h-9 min-w-56 flex-1">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={needle}
                  className="pl-8"
                  placeholder={t('map.search')}
                  onChange={(event) => setNeedle(event.target.value)}
                  onKeyDown={(event) => event.key === 'Enter' && jump()}
                />
              </div>

              <Button variant="outline" onClick={jump}>
                <Crosshair className="size-4" />
                {t('map.goTo')}
              </Button>
            </div>

            <div className="h-[34rem] overflow-hidden rounded-md border bg-muted/30">
              <WorldMap
                status={status}
                players={players}
                safehouses={overlay?.safehouses ?? []}
                focus={focus}
                onContextMenu={setTarget}
              />
            </div>

            <p className="text-xs text-muted-foreground">{t('map.rightClickHint')}</p>
          </div>

          <div className="space-y-4">
            <section className="rounded-md border">
              <header className="flex items-center gap-2 border-b px-3 py-2">
                <Users className="size-4 text-muted-foreground" />
                <h2 className="flex-1 text-sm font-medium">{t('map.players')}</h2>
                <Badge variant="secondary">{players.length}</Badge>
              </header>

              <div className="max-h-56 overflow-y-auto p-1.5">
                {players.length === 0 ? (
                  <p className="p-2 text-xs text-muted-foreground">{t('map.noPlayers')}</p>
                ) : (
                  players.map((player) => (
                    <button
                      key={player.username}
                      type="button"
                      className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent hover:text-accent-foreground"
                      onClick={() => setFocus({ x: player.x, y: player.y })}
                    >
                      <span
                        aria-hidden
                        className={`size-2 shrink-0 rounded-full ${
                          player.infected ? 'bg-destructive' : 'bg-emerald-500'
                        }`}
                      />
                      <span className="min-w-0 flex-1 truncate">{player.username}</span>
                      <span className="shrink-0 text-xs tabular-nums text-muted-foreground">
                        {player.x},{player.y}
                      </span>
                    </button>
                  ))
                )}
              </div>
            </section>

            <section className="rounded-md border">
              <header className="flex items-center gap-2 border-b px-3 py-2">
                <Home className="size-4 text-muted-foreground" />
                <h2 className="flex-1 text-sm font-medium">{t('map.safehouses')}</h2>
                <Badge variant="secondary">{overlay?.safehouses.length ?? 0}</Badge>
              </header>

              <div className="max-h-48 overflow-y-auto p-1.5">
                {(overlay?.safehouses ?? []).length === 0 ? (
                  <p className="p-2 text-xs text-muted-foreground">{t('map.noSafehouses')}</p>
                ) : (
                  (overlay?.safehouses ?? []).map((house, index) => (
                    <button
                      key={`${house.x}-${house.y}-${index}`}
                      type="button"
                      className="flex w-full flex-col rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent hover:text-accent-foreground"
                      onClick={() => setFocus({ x: house.x, y: house.y })}
                    >
                      <span className="truncate">
                        {house.title === '' ? house.owner : house.title}
                      </span>
                      <span className="text-xs text-muted-foreground">
                        {t('map.membersCount', { count: house.members.length })}
                      </span>
                    </button>
                  ))
                )}
              </div>
            </section>

            <section className="rounded-md border p-2">
              <h2 className="px-1 pb-1.5 text-sm font-medium">{t('map.places')}</h2>

              <div className="flex flex-wrap gap-1.5">
                {PLACES.map((place) => (
                  <Button
                    key={place.id}
                    variant="outline"
                    size="sm"
                    onClick={() => setFocus({ x: place.x, y: place.y })}
                  >
                    {t(`players.landmarks.${place.id}`)}
                  </Button>
                ))}
              </div>
            </section>
          </div>
        </div>
      )}

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
