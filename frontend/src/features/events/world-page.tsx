import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { CalendarDays, Eye, Play, Sun } from 'lucide-react'

import { ApiError, errorField } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Slider } from '@/components/ui/slider'
import { SectionMark } from '@/components/layout/section-mark'
import { getWorld } from '@/features/servers/world'
import { readClimate } from './climate'
import { DayArc } from './day-arc'
import { listEvents, triggerEvent } from './events'
import { formatRange } from './units'

/** How often the in-game clock is re-read; the bridge writes every ten. */
const POLL_MS = 15_000

/**
 * The frame the world runs in: the hour, the date, and how much can be
 * seen.
 *
 * The hour is set on a band from midnight to midnight rather than in a
 * number field — a day is a thing with a shape, and "make it dusk" is
 * what somebody actually wants. Daylight sits directly beneath it,
 * because the two interact: set 03:00 and nothing is visible until the
 * daylight is raised.
 */
export function WorldPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const queryClient = useQueryClient()

  const { data: world, isPending } = useQuery({
    queryKey: ['world', id],
    queryFn: () => getWorld(id),
    retry: false,
    refetchInterval: POLL_MS,
    placeholderData: (previous) => previous,
  })

  // The measured brightness, so the band is shaded by what the server is
  // actually doing rather than by an assumed sunrise. Optional on
  // purpose: without a bridge the page still sets the clock.
  const { data: climate } = useQuery({
    queryKey: ['climate', id],
    queryFn: () => readClimate(id),
    retry: false,
    refetchInterval: 30_000,
  })

  const { data: catalogue } = useQuery({
    queryKey: ['events', id],
    queryFn: () => listEvents(id),
    retry: false,
    staleTime: 300_000,
  })

  const time = world?.gameTime ?? null
  const [hour, setHour] = useState<number | null>(null)

  // The server's own hour unless one has been picked and not yet sent.
  const shown = hour ?? time?.hour ?? 12

  const setTime = useMutation({
    mutationFn: () => triggerEvent(id, 'setTime', { hour: shown }),
    onSuccess: () => {
      toast.success(t('events.timeSet', { hour: String(shown).padStart(2, '0') }))
      setHour(null)
      window.setTimeout(
        () => void queryClient.invalidateQueries({ queryKey: ['world', id] }),
        10_000,
      )
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? (errorField(error, 'detail') ?? t('events.rconFailed'))
          : t('errors.generic'),
      ),
  })

  if (isPending) {
    return <Skeleton className="h-96 w-full" />
  }

  const byId = new Map((catalogue?.items ?? []).map((action) => [action.id, action]))
  const daylightValue = climate?.values.daylight?.value

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold">{t('events.categories.world')}</h1>
        <p className="text-muted-foreground">{t('events.categoryDescriptions.world')}</p>
      </div>

      <section className="w-fit min-w-full max-w-3xl space-y-3 rounded-md border p-4">
        <SectionMark
          label={t('events.actions.setTime.title')}
          state={
            time === null
              ? undefined
              : `${String(time.hour).padStart(2, '0')}:${String(time.minute).padStart(2, '0')}`
          }
        />

        <DayArc
          hour={shown}
          minute={hour === null ? (time?.minute ?? 0) : 0}
          daylight={daylightValue}
          onPick={setHour}
        />

        <div className="flex items-center gap-3 border-t pt-3">
          <Slider
            min={0}
            max={23}
            value={[Math.min(23, Math.max(0, Math.round(shown)))]}
            aria-label={t('events.actions.setTime.title')}
            onValueChange={([next]) => setHour(next ?? 12)}
          />

          <span className="w-16 shrink-0 text-center font-mono text-sm tabular-nums">
            {String(Math.round(shown)).padStart(2, '0')}:00
          </span>

          <Button
            disabled={hour === null || setTime.isPending}
            onClick={() => setTime.mutate()}
          >
            <Play className="size-4" />
            {t('events.trigger')}
          </Button>
        </div>
      </section>

      {/* Daylight beneath the arc, because the two interact: an hour of
          03:00 shows nothing until this is raised. */}
      <ValueSection
        serverId={id}
        actionId="setDaylight"
        icon={Sun}
        min={byId.get('setDaylight')?.fields[0]?.min ?? 0}
        max={byId.get('setDaylight')?.fields[0]?.max ?? 100}
        unit={byId.get('setDaylight')?.fields[0]?.unit}
        current={
          climate?.values.daylight === undefined
            ? undefined
            : Math.round(climate.values.daylight.value * 100)
        }
      />

      <ValueSection
        serverId={id}
        actionId="setViewDistance"
        icon={Eye}
        min={byId.get('setViewDistance')?.fields[0]?.min ?? 0}
        max={byId.get('setViewDistance')?.fields[0]?.max ?? 100}
        unit={byId.get('setViewDistance')?.fields[0]?.unit}
        current={
          climate?.values.viewDistance === undefined
            ? undefined
            : Math.round(climate.values.viewDistance.value)
        }
      />

      <DateSection serverId={id} day={time?.day} month={time?.month} />
    </div>
  )
}

/** One climate value with a slider, its measured state and a button. */
function ValueSection({
  serverId,
  actionId,
  icon: Icon,
  min,
  max,
  unit,
  current,
}: {
  serverId: string
  actionId: string
  icon: typeof Sun
  min: number
  max: number
  unit?: string
  /** What the server is running, where it could be read. */
  current?: number
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const [value, setValue] = useState<number | null>(null)
  const shown = value ?? current ?? min

  const apply = useMutation({
    mutationFn: () => triggerEvent(serverId, actionId, { value: shown }),
    onSuccess: () => {
      toast.success(t('events.triggered'))
      setValue(null)
      void queryClient.invalidateQueries({ queryKey: ['climate', serverId] })
    },
    onError: () => toast.error(t('errors.generic')),
  })

  return (
    <section className="w-fit min-w-full max-w-3xl space-y-3 rounded-md border p-4">
      <div className="flex flex-wrap items-baseline gap-x-3">
        <Label
          htmlFor={`world-${actionId}`}
          className="flex items-center gap-2 text-sm font-medium"
        >
          <Icon aria-hidden className="size-4 text-muted-foreground" />
          {t(`events.actions.${actionId}.title`)}
        </Label>

        {/* State, not just the control: what the server is running now. */}
        {current !== undefined && (
          <span className="text-xs text-muted-foreground">
            {t('events.currently', { value: unit === undefined ? current : `${current}${unit === '%' ? '%' : ` ${unit}`}` })}
          </span>
        )}

        <span className="ml-auto font-mono text-xs tabular-nums text-muted-foreground">
          {formatRange(min, max, unit)}
        </span>
      </div>

      <p className="text-sm text-muted-foreground">
        {t(`events.actions.${actionId}.description`)}
      </p>

      <div className="flex items-center gap-3">
        <Slider
          id={`world-${actionId}`}
          min={min}
          max={max}
          value={[Math.min(max, Math.max(min, shown))]}
          aria-label={t(`events.actions.${actionId}.title`)}
          onValueChange={([next]) => setValue(next ?? min)}
        />

        <Input
          inputMode="numeric"
          aria-label={t(`events.actions.${actionId}.title`)}
          className="w-16 shrink-0 text-center font-mono tabular-nums"
          value={String(Math.round(shown))}
          onChange={(event) => {
            const typed = Number.parseInt(event.target.value, 10)

            setValue(Number.isNaN(typed) ? min : Math.min(max, Math.max(min, typed)))
          }}
        />

        <Button
          disabled={value === null || apply.isPending}
          onClick={() => apply.mutate()}
        >
          {t('events.trigger')}
        </Button>
      </div>
    </section>
  )
}

/** The date, which the season follows. */
function DateSection({
  serverId,
  day,
  month,
}: {
  serverId: string
  day?: number
  month?: number
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const [draft, setDraft] = useState<{ day: number; month: number } | null>(null)
  const shown = draft ?? { day: day ?? 1, month: month ?? 7 }

  const apply = useMutation({
    mutationFn: () => triggerEvent(serverId, 'setDate', shown),
    onSuccess: () => {
      toast.success(t('events.triggered'))
      setDraft(null)
      window.setTimeout(
        () => void queryClient.invalidateQueries({ queryKey: ['world', serverId] }),
        10_000,
      )
    },
    onError: () => toast.error(t('errors.generic')),
  })

  return (
    <section className="w-fit min-w-full max-w-3xl space-y-3 rounded-md border p-4">
      <Label className="flex items-center gap-2 text-sm font-medium">
        <CalendarDays aria-hidden className="size-4 text-muted-foreground" />
        {t('events.actions.setDate.title')}
      </Label>

      <p className="text-sm text-muted-foreground">{t('events.actions.setDate.description')}</p>

      <div className="flex flex-wrap items-end gap-3">
        <div className="space-y-1.5">
          <Label htmlFor="world-day" className="text-xs">
            {t('events.fields.day')}
          </Label>
          <Input
            id="world-day"
            inputMode="numeric"
            className="w-20 text-center font-mono tabular-nums"
            value={String(shown.day)}
            onChange={(event) =>
              setDraft({
                ...shown,
                day: Math.min(31, Math.max(1, Number.parseInt(event.target.value, 10) || 1)),
              })
            }
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="world-month" className="text-xs">
            {t('events.fields.month')}
          </Label>
          <Input
            id="world-month"
            inputMode="numeric"
            className="w-20 text-center font-mono tabular-nums"
            value={String(shown.month)}
            onChange={(event) =>
              setDraft({
                ...shown,
                month: Math.min(12, Math.max(1, Number.parseInt(event.target.value, 10) || 1)),
              })
            }
          />
        </div>

        <Button disabled={draft === null || apply.isPending} onClick={() => apply.mutate()}>
          {t('events.trigger')}
        </Button>
      </div>
    </section>
  )
}
