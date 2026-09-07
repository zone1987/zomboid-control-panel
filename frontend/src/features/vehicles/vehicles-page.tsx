import { useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useParams, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Car, Search, Star, Users, X } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Copyable } from '@/components/ui/copyable'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Skeleton } from '@/components/ui/skeleton'
import { SectionMark } from '@/components/layout/section-mark'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { getServer } from '@/features/servers/servers'
import { listPlayers } from '@/features/players/players'
import { useVehicleRenderer } from '@/features/map/use-vehicle-renderer'
import { VehiclePreview } from './vehicle-preview'
import { BodyTile } from './body-tile'
import { TypeFilter } from './type-filter'
import { useFavourites } from './use-favourites'
import { VehicleFacts } from './vehicle-facts'
import { VehicleTile } from './vehicle-tile'
import {
  listVehicles,
  select,
  selectBodies,
  spawnVehicle,
  typesPresent,
  WRECKS,
  type SpawnableVehicle,
  type VehicleType,
} from './vehicles'

/** How many tiles are drawn before the list has to be narrowed. */
const PAGE_SIZE = 120

export function VehiclesPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const renderer = useVehicleRenderer()

  const favouriteBodies = useFavourites(id, 'body')
  const favouriteVehicles = useFavourites(id, 'vehicle')

  const [body, setBody] = useState<string | null>(null)
  const [onlyFavourites, setOnlyFavourites] = useState(false)
  const [types, setTypes] = useState<string[]>([])
  const [needle, setNeedle] = useState('')
  const [chosen, setChosen] = useState<SpawnableVehicle | null>(null)
  // Carried in from the dossier's spawn card, so choosing a player
  // twice is not the price of arriving from there.
  const [query] = useSearchParams()
  const [player, setPlayer] = useState(query.get('player') ?? '')
  const [visible, setVisible] = useState(PAGE_SIZE)

  const search = (value: string) => {
    setNeedle(value)
    setVisible(PAGE_SIZE)
  }

  const chooseBody = (value: string | null) => {
    setBody(value)
    setVisible(PAGE_SIZE)
  }

  const toggleFavouritesFilter = () => {
    setOnlyFavourites((previous) => !previous)
    setVisible(PAGE_SIZE)
  }

  const toggleType = (type: VehicleType) => {
    setTypes((previous) =>
      previous.includes(type) ? previous.filter((entry) => entry !== type) : [...previous, type],
    )
    setVisible(PAGE_SIZE)
  }

  const clearTypes = () => {
    setTypes([])
    setVisible(PAGE_SIZE)
  }

  const { data: server } = useQuery({ queryKey: ['server', id], queryFn: () => getServer(id) })

  const { data: catalogue, isPending } = useQuery({
    queryKey: ['vehicles', id],
    queryFn: () => listVehicles(id),
    retry: false,
    staleTime: 300_000,
  })

  const { data: players } = useQuery({
    queryKey: ['players', id, true],
    queryFn: () => listPlayers(id, true),
    retry: false,
    refetchInterval: 5_000,
    placeholderData: (previous) => previous,
  })

  const online = useMemo(
    () => (players?.items ?? []).filter((entry) => entry.online),
    [players],
  )

  // Searching looks across every body, because an operator who types a
  // name does not know which shell it belongs to.
  const searching = needle.trim() !== ''

  const available = useMemo(() => typesPresent(catalogue?.items ?? []), [catalogue])

  const perType = useMemo(() => {
    const counts: Record<string, number> = {}

    for (const item of catalogue?.items ?? []) {
      counts[item.type] = (counts[item.type] ?? 0) + 1
    }

    return counts
  }, [catalogue])

  const bodies = useMemo(
    () =>
      catalogue === undefined
        ? []
        : selectBodies(
            catalogue,
            onlyFavourites,
            favouriteBodies.has,
            favouriteVehicles.has,
            types,
          ),
    [catalogue, onlyFavourites, favouriteBodies, favouriteVehicles, types],
  )

  // A body that has just been filtered out of the row above must not stay
  // selected, or the grid below would show liveries of an invisible shell.
  const reachable = body !== null && bodies.some((entry) => entry.id === body) ? body : null

  const shown = useMemo(
    () =>
      select(
        catalogue?.items ?? [],
        { needle, body: reachable, onlyFavourites, types },
        favouriteVehicles.has,
        favouriteBodies.has,
      ),
    [catalogue, reachable, needle, onlyFavourites, types, favouriteVehicles, favouriteBodies],
  )

  const markedCount = favouriteBodies.ids.length + favouriteVehicles.ids.length

  const spawn = useMutation({
    mutationFn: () => spawnVehicle(id, chosen?.script ?? '', player),
    onSuccess: (result) => {
      if (result.failed) {
        toast.error(t('vehicles.refused'), { description: result.reply })

        return
      }

      toast.success(t('vehicles.spawned', { name: chosen?.name ?? '' }))
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError && error.status === 502
          ? t('events.rconFailed')
          : t('errors.generic'),
      ),
  })

  if (isPending) {
    return <Skeleton className="h-96 w-full" />
  }

  const bodyName = (id_: string, name: string) =>
    id_ === WRECKS || name === '' ? t('vehicles.wrecks') : name

  const bodyLabel = (id_: string) =>
    (catalogue?.bodies ?? []).find((entry) => entry.id === id_)?.name ?? ''

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold">{t('vehicles.title')}</h1>
        <p className="text-muted-foreground">
          {server ? t('vehicles.descriptionFor', { server: server.name }) : t('vehicles.description')}
        </p>
      </div>

      {catalogue?.available === false && (
        <Alert>
          <Car className="size-4" />
          <AlertTitle>{t('vehicles.noCatalogue')}</AlertTitle>
          <AlertDescription>{t('vehicles.noCatalogueHint')}</AlertDescription>
        </Alert>
      )}

      <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
        <div className="space-y-3">
          <div className="flex gap-2">
            <div className="relative h-9 flex-1">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={needle}
                className="pl-8 pr-8"
                placeholder={t('vehicles.search')}
                onChange={(event) => search(event.target.value)}
              />
              {needle !== '' && (
                <button
                  type="button"
                  className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  aria-label={t('common.cancel')}
                  onClick={() => search('')}
                >
                  <X className="size-4" />
                </button>
              )}
            </div>

            <Button
              variant={onlyFavourites ? 'default' : 'outline'}
              aria-pressed={onlyFavourites}
              onClick={toggleFavouritesFilter}
            >
              <Star className={onlyFavourites ? 'size-4 fill-current' : 'size-4'} />
              {t('vehicles.favourites')}
              {markedCount > 0 && <span className="font-mono text-xs">{markedCount}</span>}
            </Button>
          </div>

          <TypeFilter
            available={available}
            active={types}
            counts={perType}
            onToggle={toggleType}
            onClear={clearTypes}
          />

          {/* The body row stays visible while a body is open, so changing
              shell is one click rather than a step backwards. */}
          {!searching && (
            <div className="space-y-2">
              <div className="flex h-6 items-center gap-2">
                <SectionMark
                  label={t('vehicles.bodies')}
                  state={onlyFavourites ? t('vehicles.favourites') : undefined}
                />

                {reachable !== null && (
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-6 px-2 text-xs"
                    onClick={() => chooseBody(null)}
                  >
                    <X className="size-3" />
                    {t('vehicles.clearBody')}
                  </Button>
                )}
              </div>

              {bodies.length === 0 ? (
                <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                  {t('vehicles.noFavourites')}
                </p>
              ) : (
                <div className="grid grid-cols-[repeat(auto-fill,minmax(9rem,1fr))] gap-2">
                  {bodies.map((entry) => (
                    <BodyTile
                      key={entry.id}
                      name={bodyName(entry.id, entry.name)}
                      count={entry.count}
                      preview={entry.preview}
                      selected={reachable === entry.id}
                      favourite={favouriteBodies.has(entry.id)}
                      renderer={renderer}
                      onSelect={() => chooseBody(reachable === entry.id ? null : entry.id)}
                      onToggleFavourite={() => favouriteBodies.toggle(entry.id)}
                    />
                  ))}
                </div>
              )}
            </div>
          )}

          {!searching && reachable === null && shown.length === 0 ? (
            <p className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">
              {t('vehicles.pickABody')}
            </p>
          ) : shown.length === 0 ? (
            <p className="rounded-md border p-8 text-center text-sm text-muted-foreground">
              {t('vehicles.noMatch')}
            </p>
          ) : (
            <div className="space-y-2 pt-3">
              <SectionMark
                label={searching ? t('vehicles.results') : t('vehicles.liveries')}
                state={
                  searching
                    ? undefined
                    : reachable === null
                      ? t('vehicles.favourites')
                      : bodyName(reachable, bodyLabel(reachable))
                }
              />

              <div className="grid grid-cols-[repeat(auto-fill,minmax(9rem,1fr))] gap-2">
                {shown.slice(0, visible).map((vehicle) => (
                  <VehicleTile
                    key={vehicle.script}
                    vehicle={vehicle}
                    selected={chosen?.script === vehicle.script}
                    favourite={favouriteVehicles.has(vehicle.script)}
                    renderer={renderer}
                    onSelect={() => setChosen(vehicle)}
                    onToggleFavourite={() => favouriteVehicles.toggle(vehicle.script)}
                  />
                ))}
              </div>

              {shown.length > visible && (
                <Button
                  variant="outline"
                  className="w-full"
                  onClick={() => setVisible((previous) => previous + PAGE_SIZE)}
                >
                  {t('vehicles.showMore', { count: shown.length - visible })}
                </Button>
              )}
            </div>
          )}
        </div>

        <div className="space-y-3">
          <section className="space-y-3 rounded-md border p-3">
            <SectionMark label={t('vehicles.chosen')} />

            {chosen === null ? (
              <p className="py-4 text-center text-sm text-muted-foreground">
                {t('vehicles.chooseOne')}
              </p>
            ) : (
              <div className="space-y-2">
                {/* Larger than a tile: this is where the livery is
                    checked before spawning. */}
                <div className="flex aspect-square items-center justify-center">
                  <VehiclePreview
                    script={chosen.script}
                    renderer={renderer}
                    ground
                    className="max-h-full w-full"
                  />
                </div>

                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-sm font-medium">{chosen.name}</p>
                    <Copyable value={chosen.script} />
                    <p className="text-xs text-muted-foreground">
                      {t(`vehicles.types.${chosen.type}`)}
                    </p>
                  </div>

                  <Button
                    variant="ghost"
                    size="icon"
                    aria-pressed={favouriteVehicles.has(chosen.script)}
                    aria-label={
                      favouriteVehicles.has(chosen.script)
                        ? t('vehicles.removeFavourite')
                        : t('vehicles.addFavourite')
                    }
                    onClick={() => favouriteVehicles.toggle(chosen.script)}
                  >
                    <Star
                      className={
                        favouriteVehicles.has(chosen.script)
                          ? 'size-4 fill-current text-primary'
                          : 'size-4 text-muted-foreground'
                      }
                    />
                  </Button>
                </div>

                {!chosen.drawable && (
                  <Badge variant="outline" className="text-xs">
                    {t('vehicles.notDrawable')}
                  </Badge>
                )}
              </div>
            )}
          </section>

          {chosen !== null && <VehicleFacts specs={chosen.specs} />}

          <section className="space-y-3 rounded-md border p-3">
            <SectionMark
              label={t('nav.players')}
              state={online.length === 0 ? undefined : String(online.length)}
            />

            <Select value={player} onValueChange={setPlayer}>
              <SelectTrigger disabled={online.length === 0}>
                <SelectValue
                  placeholder={
                    online.length === 0 ? t('events.noPlayers') : t('events.choosePlayer')
                  }
                />
              </SelectTrigger>
              <SelectContent>
                {online.map((entry) => (
                  <SelectItem key={entry.username} value={entry.username}>
                    {entry.username}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>

            <Button
              className="w-full"
              disabled={chosen === null || player === '' || spawn.isPending}
              onClick={() => spawn.mutate()}
            >
              <Users className="size-4" />
              {spawn.isPending ? t('common.loading') : t('vehicles.spawn')}
            </Button>

            {/* The vehicle appears beside the player, so somebody has to
                be there to appear beside. */}
            {online.length === 0 && (
              <p className="text-xs text-muted-foreground">{t('vehicles.needsAPlayer')}</p>
            )}
          </section>
        </div>
      </div>
    </div>
  )
}
