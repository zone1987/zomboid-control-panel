import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { RefreshCw, TrendingUp, Users } from 'lucide-react'

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Skeleton } from '@/components/ui/skeleton'
import { getServer } from '@/features/servers/servers'
import { BanDialog } from './ban-dialog'
import { PlayerList } from './player-list'
import { PlayerDossier } from './player-dossier'
import { ModerationHistory } from './moderation-history'
import { useModeration } from './use-moderation'
import { listBans, listPlayers, type Player } from './players'

/**
 * The roster and one player's dossier, side by side.
 *
 * The dossier used to be a dialog, then a 26rem sliver beside a
 * five-column table that wanted the whole width. Neither worked: the
 * table's columns duplicated what the dossier shows, and both were
 * cramped. So the left column is a *list* — name, state, one line of
 * context — and the dossier gets the room the actual content needs.
 */
export function PlayersPage() {
  const { t, i18n } = useTranslation()
  const { id = '' } = useParams()

  const [chosen, setChosen] = useState<string | null>(null)
  const [banning, setBanning] = useState<string | null>(null)

  const { data: server } = useQuery({
    queryKey: ['server', id],
    queryFn: () => getServer(id),
  })

  const { data, isPending, dataUpdatedAt, isFetching } = useQuery({
    queryKey: ['players', id, false],
    queryFn: () => listPlayers(id, false),
    refetchInterval: 3_000,
    refetchIntervalInBackground: true,
    placeholderData: (previous) => previous,
  })

  const { data: bans } = useQuery({
    queryKey: ['bans', id],
    queryFn: () => listBans(id),
    retry: false,
  })

  const moderation = useModeration(id)

  const players = useMemo(() => data?.items ?? [], [data?.items])
  const bridge = data?.bridge

  // Derived, never copied into state: an effect doing this would
  // overwrite the choice on every three-second refetch.
  const selected = useMemo<Player | null>(() => {
    if (chosen === null) {
      return null
    }

    const found = players.find((player) => player.username === chosen)

    if (found !== undefined) {
      return found
    }

    // A manually typed name, or somebody purged from the roster: still
    // actionable, so a placeholder rather than nothing.
    return {
      username: chosen,
      steamId: null,
      online: false,
      position: { x: null, y: null, z: null },
      health: null,
      infected: false,
      infectionLevel: null,
      hoursSurvived: null,
      accessLevel: null,
      skills: null,
      traits: null,
      zombieKills: null,
      survivorKills: null,
      lastSeenAt: new Date().toISOString(),
      firstSeenAt: new Date().toISOString(),
    }
  }, [chosen, players])

  const online = players.filter((player) => player.online).length

  if (isPending) {
    return <Skeleton className="h-96 w-full" />
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold">{t('players.title')}</h1>
          <p className="text-muted-foreground">
            {server
              ? t('players.descriptionFor', { server: server.name })
              : t('players.description')}
          </p>
        </div>

        {/* The timestamp without a button: the list refetches every
            three seconds, so a manual refresh only repeats what already
            happens. The time still earns its place — it says the number
            is current without anybody having to trust that it is. */}
        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
          <RefreshCw
            aria-hidden
            className={isFetching ? 'size-3 animate-spin' : 'size-3 opacity-40'}
          />
          {t('players.updatedAt', {
            time: new Date(dataUpdatedAt).toLocaleTimeString(i18n.language),
          })}
        </span>
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <StatTile icon={Users} value={online} label={t('players.onlineNow')} />
        <StatTile icon={Users} value={players.length} label={t('players.known')} />
        <StatTile icon={TrendingUp} value={bans?.items.length ?? 0} label={t('players.bansTab')} />
      </div>

      {data?.error && (
        <Alert variant="destructive">
          <AlertTitle>{t('players.bridgeUnavailable')}</AlertTitle>
          <AlertDescription>{t(data.error)}</AlertDescription>
        </Alert>
      )}

      {bridge && bridge.version !== null && bridge.version !== bridge.expectedVersion && (
        <Alert variant="warning">
          <AlertTitle>{t('players.bridgeOutdated')}</AlertTitle>
          <AlertDescription>
            {t('players.bridgeOutdatedHint', {
              installed: bridge.version,
              expected: bridge.expectedVersion,
            })}
          </AlertDescription>
        </Alert>
      )}

      {bridge?.stale && (
        <Alert>
          <AlertTitle>{t('players.bridgeStale')}</AlertTitle>
          <AlertDescription>{t('players.bridgeStaleHint')}</AlertDescription>
        </Alert>
      )}

      {/* The list needs a name's width; the dossier holds ten pip rows
          two abreast, a slider column and a note field.

          minmax(0,1fr) rather than 1fr: a bare `1fr` is
          minmax(auto,1fr), and `auto` is the content's own minimum — so
          a wide table inside the dossier pushed the whole page wider
          instead of scrolling within itself. */}
      <div className="grid items-start gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
        <PlayerList
          players={players}
          bans={bans?.items ?? []}
          selected={chosen}
          onSelect={(player) => setChosen(player.username)}
          onManual={setChosen}
        />

        <PlayerDossier
          serverId={id}
          player={selected}
          players={players}
          pending={moderation.pending}
          onKick={() => selected !== null && moderation.kick.mutate(selected)}
          onBan={() => selected !== null && setBanning(selected.username)}
          onAccessLevel={(level) =>
            selected !== null &&
            moderation.changeLevel.mutate({ username: selected.username, level })
          }
          onTeleport={(destination) =>
            selected !== null &&
            moderation.teleport.mutate({ username: selected.username, destination })
          }
        />
      </div>

      <details className="min-w-0 rounded-md border">
        <summary className="cursor-pointer px-4 py-3 text-sm font-medium">
          {t('players.historyTab')}
        </summary>

        <div className="border-t p-4">
          <ModerationHistory serverId={id} />
        </div>
      </details>

      {banning !== null && (
        <BanDialog
          username={banning}
          open
          pending={moderation.ban.isPending}
          onOpenChange={(open) => !open && setBanning(null)}
          onConfirm={(options) => {
            moderation.ban.mutate({ username: banning, ...options })
            setBanning(null)
          }}
        />
      )}
    </div>
  )
}

function StatTile({
  icon: Icon,
  value,
  label,
}: {
  icon: typeof Users
  value: number
  label: string
}) {
  return (
    <div className="flex items-center gap-3 rounded-md border p-3">
      <div className="rounded-md bg-muted p-2">
        <Icon aria-hidden className="size-4 text-muted-foreground" />
      </div>

      <div>
        <p className="font-mono text-xl leading-none font-semibold tabular-nums">{value}</p>
        <p className="mt-1 font-mono text-[11px] tracking-wide text-muted-foreground uppercase">
          {label}
        </p>
      </div>
    </div>
  )
}
