import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { AlertTriangle, CloudRain, Globe, History, Search, Skull, Volume2, Zap } from 'lucide-react'

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
  EVENT_CATEGORIES,
  isComplete,
  listEvents,
  listRecentEvents,
  triggerEvent,
  type EventAction,
  type EventCategory,
} from './events'

const CATEGORY_ICONS: Record<EventCategory, typeof CloudRain> = {
  weather: CloudRain,
  sounds: Volume2,
  actions: Zap,
  zombies: Skull,
  world: Globe,
}

/**
 * The event console, optionally narrowed to one category.
 *
 * One component rather than five: the search, the form, the trigger and
 * the confirmation are the same everywhere, and a category page differs
 * only in which actions it lists and what it calls itself.
 */
export function EventsPage({ only }: { only?: EventCategory } = {}) {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const queryClient = useQueryClient()
  const [needle, setNeedle] = useState('')
  const [chosen, setChosen] = useState<string | null>(null)
  // The inputs, held against the action they belong to. An effect
  // copying `defaultsFor(action)` into state rendered the form twice on
  // every selection and clobbered a half-filled one whenever the
  // catalogue refetched.
  const [edit, setEdit] = useState<{
    from: string
    values: Record<string, string | number | boolean>
  } | null>(null)
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
  //
  // Derived rather than chosen in an effect, and narrowed to the
  // category this page shows: `matches` filters only by the search term,
  // so falling back to its first entry landed the sounds page on a
  // weather action. The effect this replaced had the same fault.
  const listed = useMemo(
    () => matches.filter((entry) => only === undefined || entry.category === only),
    [matches, only],
  )

  const action =
    actions.find((entry) => entry.id === chosen) ?? listed[0] ?? null

  const values =
    edit !== null && action !== null && edit.from === action.id
      ? edit.values
      : action === null
        ? {}
        : defaultsFor(action)

  const setValues = (
    next: (previous: Record<string, string | number | boolean>) => Record<string, string | number | boolean>,
  ) => {
    if (action !== null) {
      setEdit({ from: action.id, values: next(values) })
    }
  }

  const trigger = useMutation({
    mutationFn: () => triggerEvent(id, action?.id ?? '', values),
    onSuccess: (result) => {
      setEdit(null)

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

  const grouped = EVENT_CATEGORIES.filter((category) => only === undefined || category === only)
    .map((category) => ({
      category,
      entries: matches.filter((entry) => entry.category === category),
    }))
    .filter((section) => section.entries.length > 0)

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
        <h1 className="text-2xl font-semibold">
          {only === undefined ? t('events.title') : t(`events.categories.${only}`)}
        </h1>
        <p className="text-muted-foreground">
          {only === undefined
            ? server
              ? t('events.descriptionFor', { server: server.name })
              : t('events.description')
            : t(`events.categoryDescriptions.${only}`)}
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
                const Icon = CATEGORY_ICONS[section.category]

                return (
                  <div key={section.category} className="space-y-1">
                    <div className="flex items-center gap-2 px-2 pt-1 text-xs font-medium text-muted-foreground">
                      <Icon className="size-3.5" />
                      {t(`events.categories.${section.category}`)}
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
