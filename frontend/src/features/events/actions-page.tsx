import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Megaphone, Plane, Play, Zap, type LucideIcon } from 'lucide-react'

import { cn } from '@/lib/utils'
import { ApiError, errorField } from '@/lib/api'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { SectionMark } from '@/components/layout/section-mark'
import { listPlayers } from '@/features/players/players'
import { listEvents, triggerEvent, type EventAction } from './events'

/** An icon per action, so a card is found by sight rather than read. */
const ICONS: Record<string, LucideIcon> = {
  lightning: Zap,
  chopper: Plane,
  broadcast: Megaphone,
}

/**
 * The staged one-shot events, as cards that do their own work.
 *
 * Three entries, so the list-and-detail shape the other categories use
 * would make every one of them a two-step: pick it, then fill a form
 * that is usually empty. Lightning and the chopper are a single click;
 * the broadcast carries its own text field, because that field *is* the
 * action.
 *
 * The card is the unit: it says what will happen, what it needs, and
 * fires it — nothing is selected first, and nothing opens elsewhere.
 */
export function ActionsPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()

  const { data: catalogue, isPending } = useQuery({
    queryKey: ['events', id],
    queryFn: () => listEvents(id),
    retry: false,
    staleTime: 300_000,
  })

  const { data: players } = useQuery({
    queryKey: ['players', id],
    queryFn: () => listPlayers(id),
    retry: false,
  })

  if (isPending) {
    return <Skeleton className="h-96 w-full" />
  }

  const actions = (catalogue?.items ?? []).filter((action) => action.category === 'actions')

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold">{t('events.categories.actions')}</h1>
        <p className="text-muted-foreground">{t('events.categoryDescriptions.actions')}</p>
      </div>

      <section className="space-y-3">
        <SectionMark label={t('events.actionsAvailable')} />

        {/* A card gets the width its content needs: two abreast on a wide
            screen rather than three full-width bands with an empty right
            half. */}
        <div className="grid gap-3 lg:grid-cols-2 xl:grid-cols-3">
          {actions.map((action) => (
            <ActionCard
              key={action.id}
              action={action}
              serverId={id}
              online={(players?.items ?? [])
                .filter((player) => player.online)
                .map((player) => player.username)}
            />
          ))}
        </div>
      </section>
    </div>
  )
}

/**
 * One action, ready to fire.
 *
 * What it needs decides its shape: nothing at all is a card with a
 * button, an optional player is a card with a chooser that may stay
 * empty, and a message is a card with a field. There is no generic form
 * here on purpose — three actions do not need one, and the form is what
 * made this page a two-step.
 */
function ActionCard({
  action,
  serverId,
  online,
}: {
  action: EventAction
  serverId: string
  online: string[]
}) {
  const { t } = useTranslation()

  const Icon = ICONS[action.id] ?? Zap
  const title = t(`events.actions.${action.id}.title`, { defaultValue: action.id })
  const description = t(`events.actions.${action.id}.description`, { defaultValue: '' })

  const playerField = action.fields.find((field) => field.type === 'player')
  const textField = action.fields.find((field) => field.type === 'text')

  const [player, setPlayer] = useState('')
  const [message, setMessage] = useState('')

  const fire = useMutation({
    mutationFn: () =>
      triggerEvent(serverId, action.id, {
        ...(playerField !== undefined && player !== '' ? { [playerField.name]: player } : {}),
        ...(textField !== undefined ? { [textField.name]: message } : {}),
      }),
    onSuccess: (result) => {
      if (result.failed) {
        toast.warning(result.reply === '' ? t('events.refused') : result.reply)

        return
      }

      toast.success(t('events.triggered'))
      setMessage('')
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? (errorField(error, 'detail') ?? t('events.rconFailed'))
          : t('errors.generic'),
      ),
  })

  // A message is the action, so an empty one has nothing to send.
  const ready = textField === undefined || message.trim() !== ''

  return (
    <div
      className={cn(
        'flex flex-col gap-3 rounded-md border p-4',
        action.available === false && 'opacity-60',
      )}
    >
      <div className="flex items-start gap-3">
        <span className="flex size-10 shrink-0 items-center justify-center rounded-md bg-primary/10">
          <Icon aria-hidden className="size-5 text-primary" />
        </span>

        <div className="min-w-0 flex-1">
          <p className="font-medium">{title}</p>
          {description !== '' && (
            <p className="text-sm leading-relaxed text-muted-foreground">{description}</p>
          )}
        </div>

        {action.available === false && (
          <Badge variant="outline" title={t('events.unavailableHint', { commands: action.missing.join(', ') })}>
            {t('events.unavailable')}
          </Badge>
        )}
      </div>

      {/* An optional player: a chooser that may stay empty, because the
          game picks one at random when nothing is named. */}
      {playerField !== undefined && (
        <div className="space-y-1.5">
          <Label htmlFor={`action-${action.id}-player`}>
            {t(`events.fields.${playerField.name}`, { defaultValue: playerField.name })}
            <span className="ml-1 text-xs font-normal text-muted-foreground">
              {t('events.optional')}
            </span>
          </Label>

          <Select value={player === '' ? undefined : player} onValueChange={setPlayer}>
            <SelectTrigger
              id={`action-${action.id}-player`}
              className="w-full"
              disabled={online.length === 0}
            >
              <SelectValue
                placeholder={online.length === 0 ? t('events.noPlayers') : t('events.anyPlayer')}
              />
            </SelectTrigger>

            <SelectContent>
              {online.map((name) => (
                <SelectItem key={name} value={name}>
                  {name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}

      {textField !== undefined && (
        <div className="space-y-1.5">
          <Label htmlFor={`action-${action.id}-text`}>
            {t(`events.fields.${textField.name}`, { defaultValue: textField.name })}
          </Label>

          <Input
            id={`action-${action.id}-text`}
            value={message}
            maxLength={textField.maxLength}
            placeholder={t('events.broadcastPlaceholder')}
            onChange={(event) => setMessage(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter' && ready && action.available !== false) {
                fire.mutate()
              }
            }}
          />

          {textField.maxLength !== undefined && (
            <p className="text-right font-mono text-xs tabular-nums text-muted-foreground">
              {message.length}/{textField.maxLength}
            </p>
          )}
        </div>
      )}

      <div className="mt-auto flex items-center gap-2 pt-1">
        <Button
          disabled={!ready || action.available === false || fire.isPending}
          onClick={() => fire.mutate()}
        >
          <Play className="size-4" />
          {fire.isPending ? t('common.loading') : t('events.trigger')}
        </Button>

        {/* Which channel carried it is a footnote, not a choice. */}
        <span className="ml-auto font-mono text-[0.65rem] uppercase tracking-wider text-muted-foreground">
          {t(`events.channel.${action.channel}`, { defaultValue: action.channel })}
        </span>
      </div>
    </div>
  )
}
