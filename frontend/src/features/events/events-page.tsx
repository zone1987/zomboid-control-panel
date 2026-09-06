import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { AlertTriangle, CloudRain, History, Search, Users, Volume2, Zap } from 'lucide-react'

import { cn } from '@/lib/utils'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { getServer } from '@/features/servers/servers'
import { listPlayers } from '@/features/players/players'
import { EventForm } from './event-form'
import {
  defaultsFor,
  GROUP_ORDER,
  isComplete,
  listEvents,
  listRecentEvents,
  triggerEvent,
  type EventAction,
  type EventActionGroup,
} from './events'

const GROUP_ICONS: Record<EventActionGroup, typeof CloudRain> = {
  weather: CloudRain,
  sounds: Volume2,
  players: Users,
  world: Zap,
}

export function EventsPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const queryClient = useQueryClient()
  const [needle, setNeedle] = useState('')
  const [chosen, setChosen] = useState<string | null>(null)
  const [values, setValues] = useState<Record<string, string | number | boolean>>({})
  const [confirming, setConfirming] = useState(false)

  const { data: server } = useQuery({ queryKey: ['server', id], queryFn: () => getServer(id) })

  const { data: catalogue, isPending } = useQuery({
    queryKey: ['events', id],
    queryFn: () => listEvents(id),
    retry: false,
    staleTime: 300_000,
  })

  const { data: players } = useQuery({
    queryKey: ['players', id, false],
    queryFn: () => listPlayers(id, false),
    retry: false,
    refetchInterval: 3_000,
    refetchIntervalInBackground: true,
    placeholderData: (previous) => previous,
  })

  const { data: recent } = useQuery({
    queryKey: ['events-recent', id],
    queryFn: () => listRecentEvents(id),
    retry: false,
    refetchInterval: 15_000,
  })

  const actions = useMemo(() => catalogue?.items ?? [], [catalogue])
  const online = (players?.items ?? []).filter((player) => player.online)

  const matches = useMemo(() => {
    const term = needle.trim().toLowerCase()

    return actions.filter(
      (action) =>
        term === '' ||
        t(`events.actions.${action.id}.title`, { defaultValue: action.id })
          .toLowerCase()
          .includes(term) ||
        action.commands.some((command) => command.includes(term)),
    )
  }, [actions, needle, t])

  // Land on something usable rather than an empty right-hand pane.
  useEffect(() => {
    if (chosen === null && matches.length > 0) {
      setChosen(matches[0].id)
    }
  }, [chosen, matches])

  const action = actions.find((entry) => entry.id === chosen) ?? null

  useEffect(() => {
    if (action !== null) {
      setValues(defaultsFor(action))
    }
  }, [action])

  const trigger = useMutation({
    mutationFn: () => triggerEvent(id, action?.id ?? '', values),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: ['events-recent', id] })

      if (result.failed) {
        toast.error(t('events.refused'), { description: result.reply })

        return
      }

      toast.success(t('events.triggered'), { description: result.reply })
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError && error.status === 502
          ? t('events.rconFailed')
          : t('errors.generic'),
      )
    },
  })

  const grouped = GROUP_ORDER.map((group) => ({
    group,
    entries: matches.filter((entry) => entry.group === group),
  })).filter((section) => section.entries.length > 0)

  const ready = action !== null && isComplete(action, values)

  const run = () => {
    if (action?.destructive === true) {
      setConfirming(true)

      return
    }

    trigger.mutate()
  }

  if (isPending) {
    return <Skeleton className="h-96 w-full" />
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold">{t('events.title')}</h1>
        <p className="text-muted-foreground">
          {server ? t('events.descriptionFor', { server: server.name }) : t('events.description')}
        </p>
      </div>

      {catalogue?.commandsKnown === false && (
        <Alert>
          <AlertTriangle className="size-4" />
          <AlertTitle>{t('events.commandsUnknown')}</AlertTitle>
          <AlertDescription>{t('events.commandsUnknownHint')}</AlertDescription>
        </Alert>
      )}

      <div className="grid gap-4 lg:grid-cols-[20rem_1fr]">
        <div className="space-y-3">
          <div className="relative h-9">
            <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={needle}
              className="pl-8"
              placeholder={t('events.search')}
              onChange={(event) => setNeedle(event.target.value)}
            />
          </div>

          <div className="space-y-3 rounded-md border p-2">
            {grouped.length === 0 ? (
              <p className="p-3 text-center text-sm text-muted-foreground">{t('events.noMatch')}</p>
            ) : (
              grouped.map((section) => {
                const Icon = GROUP_ICONS[section.group]

                return (
                  <div key={section.group} className="space-y-1">
                    <div className="flex items-center gap-2 px-2 pt-1 text-xs font-medium text-muted-foreground">
                      <Icon className="size-3.5" />
                      {t(`events.groups.${section.group}`)}
                    </div>

                    {section.entries.map((entry) => (
                      <ActionButton
                        key={entry.id}
                        action={entry}
                        active={entry.id === chosen}
                        onSelect={() => setChosen(entry.id)}
                      />
                    ))}
                  </div>
                )
              })
            )}
          </div>

          <section className="rounded-md border">
            <header className="flex items-center gap-2 border-b px-3 py-2">
              <History className="size-4 text-muted-foreground" />
              <h2 className="text-sm font-medium">{t('events.recent')}</h2>
            </header>

            <div className="max-h-64 overflow-y-auto p-1.5">
              {(recent?.items ?? []).length === 0 ? (
                <p className="p-2 text-xs text-muted-foreground">{t('events.noneYet')}</p>
              ) : (
                (recent?.items ?? []).map((entry, index) => (
                  <div key={`${entry.performedAt}-${index}`} className="px-2 py-1.5 text-xs">
                    <div className="font-medium">
                      {t(`events.actions.${entry.action}.title`, { defaultValue: entry.action })}
                    </div>
                    <div className="text-muted-foreground">
                      {new Date(entry.performedAt).toLocaleString()}
                      {entry.performedBy !== null && ` · ${entry.performedBy}`}
                    </div>
                  </div>
                ))
              )}
            </div>
          </section>
        </div>

        {action === null ? (
          <div className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">
            {t('events.chooseAction')}
          </div>
        ) : (
          <section className="space-y-4 rounded-md border p-4">
            <header className="space-y-1">
              <div className="flex flex-wrap items-center gap-2">
                <h2 className="text-lg font-medium">
                  {t(`events.actions.${action.id}.title`, { defaultValue: action.id })}
                </h2>

                <Badge variant={action.channel === 'rcon' ? 'secondary' : 'outline'}>
                  {t(`events.channel.${action.channel}`)}
                </Badge>

                {action.destructive && (
                  <Badge variant="destructive">{t('events.destructive')}</Badge>
                )}
              </div>

              <p className="text-sm text-muted-foreground">
                {t(`events.actions.${action.id}.description`, { defaultValue: '' })}
              </p>
            </header>

            {!action.available && (
              <Alert variant="destructive">
                <AlertTriangle className="size-4" />
                <AlertTitle>{t('events.unavailable')}</AlertTitle>
                <AlertDescription>
                  {t('events.unavailableHint', { commands: action.missing.join(', ') })}
                </AlertDescription>
              </Alert>
            )}

            <EventForm
              action={action}
              values={values}
              players={players?.items ?? []}
              onChange={(name, value) => setValues((previous) => ({ ...previous, [name]: value }))}
            />

            <div className="flex flex-wrap items-center gap-3 border-t pt-4">
              <Button disabled={!ready || !action.available || trigger.isPending} onClick={run}>
                {trigger.isPending ? t('common.loading') : t('events.trigger')}
              </Button>

              <span className="text-xs text-muted-foreground">
                {t('events.onlineCount', { count: online.length })}
              </span>
            </div>
          </section>
        )}
      </div>

      <AlertDialog open={confirming} onOpenChange={setConfirming}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t('events.confirmTitle', {
                action: t(`events.actions.${action?.id ?? ''}.title`, { defaultValue: '' }),
              })}
            </AlertDialogTitle>
            <AlertDialogDescription>{t('events.confirmBody')}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                setConfirming(false)
                trigger.mutate()
              }}
            >
              {t('events.trigger')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

function ActionButton({
  action,
  active,
  onSelect,
}: {
  action: EventAction
  active: boolean
  onSelect: () => void
}) {
  const { t } = useTranslation()

  return (
    <button
      type="button"
      className={cn(
        'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm',
        active ? 'bg-primary/10 font-medium' : 'hover:bg-accent hover:text-accent-foreground',
        !action.available && 'opacity-60',
      )}
      onClick={onSelect}
    >
      <span className="min-w-0 flex-1 truncate">
        {t(`events.actions.${action.id}.title`, { defaultValue: action.id })}
      </span>

      {action.channel === 'bridge' && (
        <Badge variant="outline" className="shrink-0 px-1 py-0 text-[10px]">
          {t('events.channel.bridge')}
        </Badge>
      )}
    </button>
  )
}
