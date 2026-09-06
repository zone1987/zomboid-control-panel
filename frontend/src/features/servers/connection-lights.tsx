import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ChevronDown } from 'lucide-react'

import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useActiveServer } from './active-server'
import { listServers } from './servers'
import {
  CONNECTION_ORDER,
  getConnections,
  settingsFor,
  worstOf,
  type Connection,
  type ConnectionName,
  type ConnectionState,
  type ConnectionStatus,
} from './connections'

/**
 * How often the lights are refreshed.
 *
 * The endpoint opens an FTP connection and an RCON socket, which took
 * ~860 ms measured against the live server, so this is deliberately not
 * a few seconds: it would keep a worker busy and gain nothing. Fifteen
 * seconds is fast enough to notice a server going away.
 */
const POLL_MS = 15_000

/** A dot per state. Never colour alone: each carries a title as well. */
const DOT: Record<ConnectionState, string> = {
  up: 'bg-emerald-500 shadow-[0_0_6px_1px] shadow-emerald-500/60',
  down: 'bg-red-500 shadow-[0_0_6px_1px] shadow-red-500/60',
  stale: 'bg-amber-500 shadow-[0_0_6px_1px] shadow-amber-500/60',
  unconfigured: 'bg-muted-foreground/40',
  unknown: 'bg-muted-foreground/40',
}

/**
 * Whether the panel can reach the server, as four lights.
 *
 * All four on a wide screen, because that is the whole point — one
 * glance and you know which leg is broken. Collapsed to a single
 * summary dot on a narrow one, where four labels would crowd out the
 * breadcrumb.
 */
export function ConnectionLights() {
  const { t } = useTranslation()
  const { activeServerId } = useActiveServer()

  const { data: servers } = useQuery({ queryKey: ['servers'], queryFn: listServers })
  const serverId =
    servers?.items.find((server) => server.id === activeServerId)?.id ?? servers?.items[0]?.id

  const { data } = useQuery({
    queryKey: ['connections', serverId],
    queryFn: () => getConnections(serverId as string),
    enabled: serverId !== undefined,
    retry: false,
    refetchInterval: POLL_MS,
    refetchIntervalInBackground: false,
    placeholderData: (previous) => previous,
  })

  if (serverId === undefined || data === undefined) {
    return null
  }

  return (
    <>
      {/* Wide: every light, labelled. */}
      <div className="hidden items-center gap-3 md:flex">
        {CONNECTION_ORDER.map((name) => (
          <Light key={name} name={name} connection={data[name]} to={settingsFor(name, serverId)} />
        ))}
      </div>

      {/* Narrow: one dot that opens the four. */}
      <DropdownMenu>
        <DropdownMenuTrigger asChild className="md:hidden">
          <Button
            variant="ghost"
            size="sm"
            className="h-6 gap-1.5 px-2"
            aria-label={t('connections.title')}
          >
            <Dot state={worstOf(data)} />
            <ChevronDown className="size-3 text-muted-foreground" />
          </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" className="w-56 p-2">
          <p className="px-1 pb-2 font-mono text-[0.65rem] uppercase tracking-wider text-muted-foreground">
            {t('connections.title')}
          </p>

          <div className="space-y-2">
            {CONNECTION_ORDER.map((name) => (
              <Light
                key={name}
                name={name}
                connection={data[name]}
                to={settingsFor(name, serverId)}
                showState
              />
            ))}
          </div>
        </DropdownMenuContent>
      </DropdownMenu>
    </>
  )
}

function Light({
  name,
  connection,
  to,
  showState = false,
}: {
  name: ConnectionName
  connection: ConnectionStatus[ConnectionName]
  to: string
  showState?: boolean
}) {
  const { t } = useTranslation()

  const label = t(`connections.${name}`)
  const state = t(`connections.states.${connection.state}`)
  const detail = connection.detail === null ? null : t(connection.detail, { defaultValue: '' })

  // The whole thing carries a title, so the state is readable without
  // relying on the colour — one man in twelve cannot separate red from
  // green.
  const title = [label, state, detail].filter((part) => part !== null && part !== '').join(' · ')

  return (
    <Button
      asChild
      variant="ghost"
      size="sm"
      // A button rather than a link with an underline: it sits in a bar
      // of icon buttons, and matching them reads as a control instead of
      // as prose that happens to be clickable.
      // Sized for the footer bar, which is deliberately shorter than the
      // header: a control taller than its bar makes the bar grow.
      className={cn('h-6 gap-1.5 px-2 text-xs font-normal', showState && 'w-full justify-between')}
    >
      <Link to={to} title={`${title} — ${t('connections.openSettings')}`}>
        <span className="flex items-center gap-1.5">
          <Dot state={connection.state} />
          <span className={cn(connection.state !== 'up' && 'font-medium')}>{label}</span>
        </span>

        {showState && <span className="text-muted-foreground">{state}</span>}
      </Link>
    </Button>
  )
}

function Dot({ state }: { state: ConnectionState }) {
  return <span aria-hidden className={cn('size-2 shrink-0 rounded-full', DOT[state])} />
}

export type { Connection }
