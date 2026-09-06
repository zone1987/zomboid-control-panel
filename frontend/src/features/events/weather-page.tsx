import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Play } from 'lucide-react'

import { cn } from '@/lib/utils'
import { ApiError } from '@/lib/api'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Slider } from '@/components/ui/slider'
import { SectionMark } from '@/components/layout/section-mark'
import { WorldStrip } from '@/features/servers/world-strip'
import { listEvents, triggerEvent, type EventAction } from './events'
import { actionsOf, WEATHER_PRESETS, type WeatherPreset } from './weather-presets'

/**
 * How often the bridge writes time and weather.
 *
 * Mirrors `SECONDS_BETWEEN_WORLD_WRITES` in the Lua, which is asserted
 * against this by a test rather than trusted.
 */
const BRIDGE_WORLD_WRITE_SECONDS = 10

/**
 * The weather: what it is, what you can make it, and what that will do.
 *
 * Choosing a preset does not fire it. It opens the steps it is about to
 * take with their values, so an operator reads "rain at 60, clouds at
 * 80" before pressing anything and can change either. The reference
 * panel has neither icons nor a live state, so it presses "start rain"
 * and hopes; this shows the state, the choice and the consequence.
 *
 * Stopping is the Clear preset, which sets stopWeather — the same
 * control as every other condition rather than a special case.
 */
export function WeatherPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const queryClient = useQueryClient()
  const [chosen, setChosen] = useState<WeatherPreset | null>(null)
  const [values, setValues] = useState<Record<string, number>>({})

  const { data: catalogue, isPending } = useQuery({
    queryKey: ['events', id],
    queryFn: () => listEvents(id),
    retry: false,
  })

  const byId = useMemo(
    () => new Map((catalogue?.items ?? []).map((action) => [action.id, action])),
    [catalogue],
  )

  /** Each step paired with the action that carries its bounds. */
  const steps = useMemo(
    () =>
      (chosen?.steps ?? []).map((step) => ({
        step,
        action: byId.get(step.action),
        // One step sets at most one value, so its first input names it.
        field: Object.keys(step.inputs ?? {})[0],
      })),
    [chosen, byId],
  )

  const choose = (preset: WeatherPreset) => {
    setChosen(preset)

    // Seeded from the preset rather than the field defaults: its own
    // numbers are what "Downpour" means.
    const seeded: Record<string, number> = {}

    for (const step of preset.steps) {
      for (const [field, value] of Object.entries(step.inputs ?? {})) {
        seeded[`${step.action}.${field}`] = value
      }
    }

    setValues(seeded)
  }

  const fire = useMutation({
    mutationFn: async () => {
      // Sequentially, not in parallel: the game applies these to one
      // world, and "stop the weather, then set the clouds" only means
      // what it says in that order.
      for (const { step, action, field } of steps) {
        if (action?.available === false) {
          continue
        }

        const inputs =
          field === undefined ? {} : { [field]: values[`${step.action}.${field}`] ?? 0 }

        await triggerEvent(id, step.action, inputs)
      }
    },
    onSuccess: () => {
      toast.success(
        t('events.presetApplied', {
          preset: chosen === null ? '' : t(`events.presets.${chosen.id}`),
        }),
      )

      // Bridge 0.14 writes time and weather every ten seconds, so one
      // refetch shortly after is enough to show what was just set.
      window.setTimeout(
        () => void queryClient.invalidateQueries({ queryKey: ['world', id] }),
        BRIDGE_WORLD_WRITE_SECONDS * 1000,
      )
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

  /** A preset whose every step is unavailable cannot do anything at all. */
  const usable = (preset: WeatherPreset) =>
    actionsOf(preset).some((action) => byId.get(action)?.available !== false)

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold">{t('events.categories.weather')}</h1>
        <p className="text-muted-foreground">{t('events.categoryDescriptions.weather')}</p>
      </div>

      <section className="space-y-2">
        <SectionMark label={t('events.weatherNow')} state={t('events.live')} />
        <WorldStrip serverId={id} />
      </section>

      <section className="space-y-2">
        <SectionMark label={t('events.weatherSet')} />

        <div className="grid grid-cols-[repeat(auto-fill,minmax(7rem,1fr))] gap-2">
          {WEATHER_PRESETS.map((preset) => {
            const enabled = usable(preset)
            const picked = chosen?.id === preset.id

            return (
              <button
                key={preset.id}
                type="button"
                aria-pressed={picked}
                disabled={!enabled}
                title={enabled ? undefined : t('events.presetUnavailable')}
                className={cn(
                  'pz-interactive flex flex-col items-center gap-2 rounded-md border p-3 text-center',
                  picked
                    ? 'border-primary bg-primary/10'
                    : enabled
                      ? 'hover:bg-accent/50'
                      : 'opacity-40',
                )}
                onClick={() => choose(preset)}
              >
                <preset.icon
                  className={cn('size-7', picked ? 'text-primary' : 'text-muted-foreground')}
                />
                <span className="text-xs leading-tight">{t(`events.presets.${preset.id}`)}</span>
              </button>
            )
          })}
        </div>
      </section>

      {chosen !== null && (
        <section className="max-w-2xl space-y-3 rounded-md border p-4">
          <SectionMark label={t('events.willDo')} state={t(`events.presets.${chosen.id}`)} />

          <div className="space-y-3">
            {steps.map(({ step, action, field }) => (
              <StepRow
                key={`${step.action}.${field ?? ''}`}
                action={action}
                actionId={step.action}
                field={field}
                value={field === undefined ? undefined : values[`${step.action}.${field}`]}
                onChange={(next) =>
                  setValues((previous) => ({ ...previous, [`${step.action}.${field}`]: next }))
                }
              />
            ))}
          </div>

          <div className="flex items-center gap-2 border-t pt-3">
            <Button disabled={fire.isPending} onClick={() => fire.mutate()}>
              <Play className="size-4" />
              {fire.isPending ? t('common.loading') : t('events.applyWeather')}
            </Button>

            <Button variant="ghost" onClick={() => setChosen(null)}>
              {t('common.cancel')}
            </Button>
          </div>
        </section>
      )}
    </div>
  )
}

/**
 * One step of a preset: what it does, and the value it does it with.
 *
 * A step with no value — "stop the weather" — is a line of text, because
 * a slider for something with no number would be a control that does
 * nothing. A step the server cannot do is struck through and says so
 * rather than vanishing: a preset that quietly drops half its work is
 * worse than one that admits it.
 */
function StepRow({
  action,
  actionId,
  field,
  value,
  onChange,
}: {
  action?: EventAction
  actionId: string
  field?: string
  value?: number
  onChange: (value: number) => void
}) {
  const { t } = useTranslation()

  const title = t(`events.actions.${actionId}.title`, { defaultValue: actionId })
  const unavailable = action?.available === false

  if (field === undefined) {
    return (
      <div className="flex items-center gap-2 text-sm">
        <span className={cn('flex-1', unavailable && 'text-muted-foreground line-through')}>
          {title}
        </span>
        {unavailable && <Badge variant="outline">{t('events.unavailable')}</Badge>}
      </div>
    )
  }

  const declared = action?.fields.find((candidate) => candidate.name === field)
  const min = typeof declared?.min === 'number' ? declared.min : 0
  const max = typeof declared?.max === 'number' ? declared.max : 100
  const current = Math.min(max, Math.max(min, value ?? min))

  return (
    <div className={cn('space-y-2', unavailable && 'opacity-50')}>
      <div className="flex items-baseline justify-between gap-2">
        <Label htmlFor={`step-${actionId}-${field}`}>{title}</Label>

        {unavailable ? (
          <Badge variant="outline">{t('events.unavailable')}</Badge>
        ) : (
          <span className="font-mono text-xs tabular-nums text-muted-foreground">
            {min}–{max}
          </span>
        )}
      </div>

      <div className="flex items-center gap-3">
        <Slider
          id={`step-${actionId}-${field}`}
          min={min}
          max={max}
          value={[current]}
          disabled={unavailable}
          aria-label={title}
          onValueChange={([next]) => onChange(next ?? min)}
        />

        {/* The number stays typable: aiming a slider at 80 is worse than
            saying 80. */}
        <Input
          inputMode="numeric"
          aria-label={title}
          disabled={unavailable}
          className="w-16 shrink-0 text-center font-mono tabular-nums"
          value={String(current)}
          onChange={(event) => {
            const typed = Number.parseInt(event.target.value, 10)

            onChange(Number.isNaN(typed) ? min : Math.min(max, Math.max(min, typed)))
          }}
        />
      </div>
    </div>
  )
}
