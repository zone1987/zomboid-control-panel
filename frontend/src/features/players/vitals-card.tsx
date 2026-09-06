import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ChevronDown, HeartPulse, Scale } from 'lucide-react'

import { cn } from '@/lib/utils'
import { ApiError, errorField } from '@/lib/api'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Slider } from '@/components/ui/slider'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible'
import {
  healPlayer,
  PRIMARY_STATS,
  readVitals,
  setStat,
  setWeight,
  type CharacterStat,
} from './players'

/**
 * A character's condition, adjustable.
 *
 * **Every bound comes from the game**, which is the whole reason this
 * needed the bridge rather than a table: measured on a live server the
 * twenty-four statistics run 0..1, 0..100, −1..1, 20..40 and even
 * 0..0.51. `Stats::set` clamps rather than refusing, so a guessed range
 * would have set something else and reported success.
 *
 * Nine of them come first because a dossier answers "why is this player
 * complaining"; the other fifteen sit behind a disclosure rather than
 * being hidden, since knowing the game runs one at 40 % is worth having.
 */
export function VitalsCard({
  serverId,
  username,
  online,
}: {
  serverId: string
  username: string
  online: boolean
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const { data, isPending, error } = useQuery({
    queryKey: ['player-vitals', serverId, username],
    queryFn: () => readVitals(serverId, username),
    retry: false,
    enabled: online,
  })

  const refresh = () =>
    void queryClient.invalidateQueries({ queryKey: ['player-vitals', serverId, username] })

  const heal = useMutation({
    mutationFn: () => healPlayer(serverId, username),
    onSuccess: (result) => {
      toast.success(t('players.healed', { parts: result.parts ?? 0 }))
      refresh()
    },
    onError: (failure) =>
      toast.error(
        failure instanceof ApiError
          ? (errorField(failure, 'detail') ?? t('errors.generic'))
          : t('errors.generic'),
      ),
  })

  if (!online) {
    return <p className="text-sm text-muted-foreground">{t('players.vitalsNeedOnline')}</p>
  }

  if (isPending) {
    return <Skeleton className="h-64 w-full" />
  }

  if (data === undefined) {
    return (
      <p className="text-sm text-muted-foreground">
        {error instanceof ApiError
          ? (errorField(error, 'detail') ?? t('players.vitalsUnavailable'))
          : t('players.vitalsUnavailable')}
      </p>
    )
  }

  const primary = PRIMARY_STATS.filter((name) => data.stats[name] !== undefined)
  const rest = Object.keys(data.stats)
    .filter((name) => !(PRIMARY_STATS as readonly string[]).includes(name))
    .sort()

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <Button disabled={heal.isPending} onClick={() => heal.mutate()}>
          <HeartPulse className="size-4" />
          {heal.isPending ? t('common.loading') : t('players.heal')}
        </Button>

        {data.profession !== null && (
          <Badge variant="secondary">{t('players.professionIs', { job: data.profession })}</Badge>
        )}

        <WeightRow serverId={serverId} username={username} weight={data.weight} onDone={refresh} />
      </div>

      <div className="space-y-4 border-t pt-3">
        {primary.map((name) => (
          <StatRow
            key={name}
            serverId={serverId}
            username={username}
            name={name}
            stat={data.stats[name] as CharacterStat}
            onDone={refresh}
          />
        ))}
      </div>

      {/* The other fifteen: shown rather than hidden, because knowing the
          game runs one at 40 % is worth having even where nobody would
          normally reach for it. */}
      <Collapsible>
        <CollapsibleTrigger asChild>
          <Button variant="ghost" size="sm" className="w-full justify-between">
            {t('players.moreStats', { count: rest.length })}
            <ChevronDown className="size-4" />
          </Button>
        </CollapsibleTrigger>

        <CollapsibleContent className="space-y-4 pt-3">
          {rest.map((name) => (
            <StatRow
              key={name}
              serverId={serverId}
              username={username}
              name={name}
              stat={data.stats[name] as CharacterStat}
              onDone={refresh}
            />
          ))}
        </CollapsibleContent>
      </Collapsible>
    </div>
  )
}

/**
 * One statistic, with its own bounds.
 *
 * The step follows the range: a 0..1 stat moves in hundredths, a 0..100
 * one in whole numbers. Anything else would make one of the two either
 * unusable or absurdly precise.
 */
function StatRow({
  serverId,
  username,
  name,
  stat,
  onDone,
}: {
  serverId: string
  username: string
  name: string
  stat: CharacterStat
  onDone: () => void
}) {
  const { t } = useTranslation()

  const [edit, setEdit] = useState<{ from: number; to: number } | null>(null)
  const shown = edit !== null && edit.from === stat.value ? edit.to : stat.value

  const span = stat.max - stat.min
  const step = span <= 2 ? 0.01 : 1
  const decimals = span <= 2 ? 2 : 0

  const apply = useMutation({
    mutationFn: () => setStat(serverId, username, name, shown),
    onSuccess: () => {
      toast.success(t('players.statSet', { stat: t(`players.stats.${name}`, { defaultValue: name }) }))
      setEdit(null)
      onDone()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? (errorField(error, 'detail') ?? t('errors.generic'))
          : t('errors.generic'),
      ),
  })

  const changed = Math.abs(shown - stat.value) > step / 2

  return (
    <div className="space-y-1.5">
      <div className="flex flex-wrap items-baseline gap-x-2">
        <Label htmlFor={`stat-${name}`} className="text-sm">
          {t(`players.stats.${name}`, { defaultValue: name })}
        </Label>

        {/* The range is the game's own, which is why it is printed: no
            two of these agree. */}
        <span className="ml-auto font-mono text-xs tabular-nums text-muted-foreground">
          {stat.min.toFixed(decimals)}–{stat.max.toFixed(decimals)}
        </span>
      </div>

      <div className="flex items-center gap-2">
        <Slider
          id={`stat-${name}`}
          min={stat.min}
          max={stat.max}
          step={step}
          value={[Math.min(stat.max, Math.max(stat.min, shown))]}
          aria-label={t(`players.stats.${name}`, { defaultValue: name })}
          onValueChange={([next]) => setEdit({ from: stat.value, to: next ?? stat.min })}
        />

        <span
          className={cn(
            'w-14 shrink-0 text-right font-mono text-sm tabular-nums',
            changed && 'text-primary',
          )}
        >
          {shown.toFixed(decimals)}
        </span>

        <Button
          size="sm"
          variant={changed ? 'default' : 'ghost'}
          disabled={!changed || apply.isPending}
          onClick={() => apply.mutate()}
        >
          {t('climate.apply')}
        </Button>
      </div>
    </div>
  )
}

/** The weight, which lives on the nutrition rather than the statistics. */
function WeightRow({
  serverId,
  username,
  weight,
  onDone,
}: {
  serverId: string
  username: string
  weight: number | null
  onDone: () => void
}) {
  const { t } = useTranslation()

  const [edit, setEdit] = useState<{ from: number; to: number } | null>(null)
  const current = weight ?? 80
  const shown = edit !== null && edit.from === current ? edit.to : current

  const apply = useMutation({
    mutationFn: () => setWeight(serverId, username, shown),
    onSuccess: () => {
      toast.success(t('players.weightSet', { weight: shown }))
      setEdit(null)
      onDone()
    },
    onError: () => toast.error(t('errors.generic')),
  })

  if (weight === null) {
    return null
  }

  return (
    <div className="flex items-center gap-2">
      <Label htmlFor="player-weight" className="flex items-center gap-1.5 text-sm">
        <Scale aria-hidden className="size-4 text-muted-foreground" />
        {t('players.weight')}
      </Label>

      <Input
        id="player-weight"
        inputMode="numeric"
        className="w-16 text-center font-mono tabular-nums"
        value={String(Math.round(shown))}
        onChange={(event) => {
          const typed = Number.parseInt(event.target.value, 10)

          setEdit({ from: current, to: Number.isNaN(typed) ? current : Math.min(200, Math.max(30, typed)) })
        }}
      />

      <span className="font-mono text-xs text-muted-foreground">kg</span>

      {Math.round(shown) !== Math.round(current) && (
        <Button size="sm" disabled={apply.isPending} onClick={() => apply.mutate()}>
          {t('climate.apply')}
        </Button>
      )}
    </div>
  )
}
