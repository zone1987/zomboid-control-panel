import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import {
  Activity,
  ArrowUpCircle,
  Cable,
  Map,
  MessagesSquare,
  Server,
  Terminal,
  TrendingUp,
  Users,
} from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { SectionMark } from '@/components/layout/section-mark'
import { useAuth } from '@/features/auth/auth-context'
import { getBridgeStatus, listServers } from '@/features/servers/servers'
import { useActiveServer } from '@/features/servers/active-server'
import { WorldStrip } from '@/features/servers/world-strip'
import { listHistory, listPlayers } from '@/features/players/players'
import { getPanelVersion, hasUpdate, type PanelVersion } from '@/features/panel/panel-version'
import { SectionTile } from './section-tile'
import { StatCard } from './stat-card'

/** How many activity entries the timeline shows. */
const TIMELINE_LENGTH = 12

export function DashboardPage() {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const { activeServerId } = useActiveServer()

  const { data: servers, isPending } = useQuery({
    queryKey: ['servers'],
    queryFn: listServers,
    enabled: can('servers.view') || can('players.view'),
  })

  const server = servers?.items.find((entry) => entry.id === activeServerId) ?? servers?.items[0]
  const id = server?.id ?? ''

  const { data: players } = useQuery({
    queryKey: ['players', id, false],
    queryFn: () => listPlayers(id, false),
    enabled: id !== '' && can('players.view'),
    retry: false,
    refetchInterval: 15_000,
    placeholderData: (previous) => previous,
  })

  const { data: history } = useQuery({
    queryKey: ['players-history', id],
    queryFn: () => listHistory(id),
    enabled: id !== '' && can('players.view'),
    retry: false,
    refetchInterval: 30_000,
  })

  const { data: bridge } = useQuery({
    queryKey: ['bridge', id],
    queryFn: () => getBridgeStatus(id),
    enabled: id !== '' && can('servers.bridge'),
    retry: false,
  })

  const { data: panel } = useQuery({
    queryKey: ['panel-version'],
    queryFn: getPanelVersion,
    retry: false,
    staleTime: 3_600_000,
  })

  const roster = players?.items ?? []
  const online = roster.filter((player) => player.online)

  const timeline = useMemo(
    () => (history?.items ?? []).slice(0, TIMELINE_LENGTH),
    [history],
  )

  if (isPending) {
    return <Skeleton className="h-96 w-full" />
  }

  if (server === undefined) {
    return (
      <div className="mx-auto max-w-xl space-y-4 py-12 text-center">
        <h1 className="text-2xl font-semibold">{t('nav.dashboard')}</h1>
        <p className="text-muted-foreground">{t('servers.emptyHint')}</p>
        <Button asChild>
          <Link to="/servers">{t('servers.add')}</Link>
        </Button>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">{server.name}</h1>
          <p className="text-muted-foreground">{t('dashboard.subtitle')}</p>
        </div>

        <Button variant="outline" size="sm" asChild>
          <Link to={`/servers/${server.id}`}>
            <Server className="size-4" />
            {t('nav.servers')}
          </Link>
        </Button>
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <StatCard
          accent
          icon={Users}
          value={online.length}
          unit={t('dashboard.playersUnit')}
          caption={t('dashboard.online')}
        />
        <StatCard
          icon={TrendingUp}
          value={players?.bridge?.playerCount ?? online.length}
          caption={t('dashboard.reportedByBridge')}
        />
        <StatCard icon={Activity} value={roster.length} caption={t('dashboard.known')} />
      </div>

      <WorldStrip serverId={server.id} />

      <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <section className="space-y-3">
          <SectionMark label={t('dashboard.activity')} state={t('dashboard.live')} />

          <div className="rounded-md border">
            {timeline.length === 0 ? (
              <p className="p-6 text-center text-sm text-muted-foreground">
                {t('dashboard.noActivity')}
              </p>
            ) : (
              <ul className="divide-y">
                {timeline.map((entry, index) => (
                  <li
                    key={`${entry.performedAt}-${index}`}
                    className="flex items-baseline gap-3 px-4 py-2.5 text-sm"
                  >
                    <time className="font-mono text-xs text-muted-foreground">
                      {new Date(entry.performedAt).toLocaleTimeString(i18n.language, {
                        hour: '2-digit',
                        minute: '2-digit',
                      })}
                    </time>

                    <span className="min-w-0 flex-1 truncate">
                      <span className="font-medium">{entry.username}</span>
                      {entry.performedBy !== null && (
                        <span className="text-muted-foreground">
                          {' · '}
                          {entry.performedBy}
                        </span>
                      )}
                    </span>

                    <span className="shrink-0 font-mono text-xs text-muted-foreground">
                      {t(`players.actionName.${entry.action}`, { defaultValue: entry.action })}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </div>

          {/* Joins and leaves are not recorded yet, so the timeline must
              not imply it saw everything that happened. */}
          <p className="text-xs text-muted-foreground">{t('dashboard.activityScope')}</p>
        </section>

        <section className="space-y-3">
          <SectionMark label={t('dashboard.sections')} />

          <div className="space-y-2">
            {can('players.view') && (
              <SectionTile
                icon={Users}
                label={t('nav.players')}
                state={t('dashboard.onlineCount', { count: online.length })}
                tone={online.length > 0 ? 'good' : 'neutral'}
                to={`/servers/${server.id}/players`}
              />
            )}

            {can('players.view') && (
              <SectionTile
                icon={Map}
                label={t('nav.map')}
                state={t('dashboard.openMap')}
                to={`/servers/${server.id}/map`}
              />
            )}

            {can('chat.read') && (
              <SectionTile
                icon={MessagesSquare}
                label={t('nav.chat')}
                state={t('dashboard.openChat')}
                to={`/servers/${server.id}/chat`}
              />
            )}

            {can('console.use') && (
              <SectionTile
                icon={Terminal}
                label={t('nav.console')}
                state={
                  server.rcon === null ? t('dashboard.rconMissing') : t('dashboard.rconReady')
                }
                tone={server.rcon === null ? 'warn' : 'good'}
                to={`/servers/${server.id}/console`}
              />
            )}

            <SectionTile
              icon={Cable}
              label={t('servers.bridgeTitle')}
              state={bridgeState(bridge, players, t)}
              tone={bridgeTone(bridge, players)}
              to={`/servers/${server.id}`}
            />

            <SectionTile
              icon={ArrowUpCircle}
              label={t('panel.updates')}
              state={updateState(panel, bridge, t)}
              tone={updateTone(panel, bridge)}
              to="/settings"
            />
          </div>
        </section>
      </div>
    </div>
  )
}

type Translate = (key: string, options?: Record<string, unknown>) => string

/** What the bridge tile says, in order of what the operator can act on. */
function bridgeState(
  bridge: { installed: boolean; installedVersion: string | null; upToDate: boolean } | undefined,
  players: { bridge: { stale: boolean } | null } | undefined,
  t: Translate,
): string {
  if (bridge?.installed === false) {
    return t('servers.bridgeNotInstalled')
  }

  if (players?.bridge?.stale === true) {
    return t('dashboard.bridgeStale')
  }

  if (bridge?.upToDate === false) {
    return t('servers.bridgeUpdateAvailable')
  }

  if (bridge?.installedVersion !== null && bridge?.installedVersion !== undefined) {
    return bridge.installedVersion
  }

  return t('dashboard.bridgeUnknown')
}

function bridgeTone(
  bridge: { installed: boolean; upToDate: boolean } | undefined,
  players: { bridge: { stale: boolean } | null } | undefined,
): 'neutral' | 'good' | 'warn' {
  if (bridge?.installed === false || players?.bridge?.stale === true) {
    return 'warn'
  }

  return bridge?.upToDate === true ? 'good' : 'neutral'
}

type BridgeShape = { installed: boolean; upToDate: boolean } | undefined

/** Names whichever update is outstanding, the panel's taking precedence. */
function updateState(panel: PanelVersion | undefined, bridge: BridgeShape, t: Translate): string {
  if (hasUpdate(panel)) {
    return t('panel.updateAvailable', { version: panel?.latest })
  }

  if (bridge?.installed === true && bridge.upToDate === false) {
    return t('servers.bridgeUpdateAvailable')
  }

  if (panel?.upToDate === null) {
    return t('panel.updateUnknown')
  }

  return t('panel.upToDate')
}

function updateTone(panel: PanelVersion | undefined, bridge: BridgeShape): 'neutral' | 'good' | 'warn' {
  if (hasUpdate(panel) || (bridge?.installed === true && bridge.upToDate === false)) {
    return 'warn'
  }

  return panel?.upToDate === true ? 'good' : 'neutral'
}
