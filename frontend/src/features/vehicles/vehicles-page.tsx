import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Car, Search, Users, X } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
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
import { VehicleTile } from './vehicle-tile'
import {
  listVehicles,
  matches,
  representatives,
  spawnVehicle,
  WRECKS,
  type SpawnableVehicle,
} from './vehicles'

/** How many tiles are drawn before the list has to be narrowed. */
const PAGE_SIZE = 120

export function VehiclesPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const renderer = useVehicleRenderer()

  const [body, setBody] = useState<string | null>(null)
  const [needle, setNeedle] = useState('')
  const [chosen, setChosen] = useState<SpawnableVehicle | null>(null)
  const [player, setPlayer] = useState('')
  const [visible, setVisible] = useState(PAGE_SIZE)

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

  const shown = useMemo(() => {
    if (catalogue === undefined) {
      return []
    }

    if (searching) {
      return catalogue.items.filter((vehicle) => matches(vehicle, needle))
    }

    if (body === null) {
      return representatives(catalogue)
    }

    return catalogue.items.filter((vehicle) => vehicle.body === body)
  }, [catalogue, body, needle, searching])

  useEffect(() => setVisible(PAGE_SIZE), [body, needle])

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

      <div className="grid gap-4 lg:grid-cols-[1fr_18rem]">
        <div className="space-y-3">
          <div className="relative h-9">
            <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={needle}
              className="pl-8 pr-8"
              placeholder={t('vehicles.search')}
              onChange={(event) => setNeedle(event.target.value)}
            />
            {needle !== '' && (
              <button
                type="button"
                className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                aria-label={t('common.cancel')}
                onClick={() => setNeedle('')}
              >
                <X className="size-4" />
              </button>
            )}
          </div>

          {/* The body row stays visible while a body is open, so changing
              shell is one click rather than a step backwards. */}
          {!searching && (
            <div className="space-y-2">
              <SectionMark
                label={t('vehicles.bodies')}
                state={body === null ? undefined : t('vehicles.filtered')}
              />

              <div className="flex flex-wrap gap-1.5">
                <Button
                  variant={body === null ? 'secondary' : 'ghost'}
                  size="sm"
                  className="h-7"
                  onClick={() => setBody(null)}
                >
                  {t('vehicles.allBodies')}
                </Button>

                {(catalogue?.bodies ?? []).map((entry) => (
                  <Button
                    key={entry.id}
                    variant={body === entry.id ? 'secondary' : 'ghost'}
                    size="sm"
                    className="h-7"
                    onClick={() => setBody(entry.id)}
                  >
                    {bodyName(entry.id, entry.name)}
                    <span className="ml-1 text-muted-foreground">{entry.count}</span>
                  </Button>
                ))}
              </div>
            </div>
          )}

          {shown.length === 0 ? (
            <p className="rounded-md border p-8 text-center text-sm text-muted-foreground">
              {t('vehicles.noMatch')}
            </p>
          ) : (
            <>
              <div className="grid grid-cols-[repeat(auto-fill,minmax(7.5rem,1fr))] gap-2">
                {shown.slice(0, visible).map((vehicle) => (
                  <VehicleTile
                    key={vehicle.script}
                    vehicle={vehicle}
                    selected={chosen?.script === vehicle.script}
                    renderer={renderer}
                    onSelect={() => setChosen(vehicle)}
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
            </>
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
                <VehiclePreview
                  script={chosen.script}
                  renderer={renderer}
                  className="h-24 w-full"
                />

                <div>
                  <p className="text-sm font-medium">{chosen.name}</p>
                  <p className="font-mono text-xs text-muted-foreground">{chosen.script}</p>
                </div>

                {!chosen.drawable && (
                  <Badge variant="outline" className="text-xs">
                    {t('vehicles.notDrawable')}
                  </Badge>
                )}
              </div>
            )}
          </section>

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
