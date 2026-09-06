import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Play, TriangleAlert } from 'lucide-react'

import { cn } from '@/lib/utils'
import { ApiError, errorField } from '@/lib/api'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Slider } from '@/components/ui/slider'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { SectionMark } from '@/components/layout/section-mark'
import { WorldStrip } from '@/features/servers/world-strip'
import { formatRange } from './units'
import { WEATHER_STAGES } from './weather-stages'
import { listEvents, triggerEvent, type EventAction } from './events'
import {
  actionsOf,
  PRESET_GROUPS,
  presetsOf,
  type PresetGroup,
  type WeatherPreset,
} from './weather-presets'

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
  const [values, setValues] = useState<Record<string, number | boolean>>({})
  // What the server said when it refused a step, keyed by action id: a
  // preset half-applied must say which half, not claim success.
  const [refused, setRefused] = useState<Record<string, string>>({})

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

    setRefused({})

    // Seeded from the preset rather than the field defaults: its own
    // numbers are what "Downpour" means.
    const seeded: Record<string, number | boolean> = {}

    for (const step of preset.steps) {
      for (const [field, value] of Object.entries(step.inputs ?? {})) {
        seeded[`${step.action}.${field}`] = value
      }
    }

    setValues(seeded)
  }

  const fire = useMutation({
    mutationFn: async () => {
      const declined: Record<string, string> = {}

      // Sequentially, not in parallel: the game applies these to one
      // world, and "stop the weather, then set the clouds" only means
      // what it says in that order.
      for (const { step, action, field } of steps) {
        if (action?.available === false) {
          continue
        }

        const inputs =
          field === undefined ? {} : { [field]: values[`${step.action}.${field}`] ?? 0 }

        try {
          const result = await triggerEvent(id, step.action, inputs)

          // The bridge reads back rather than trusting the setter, so a
          // 200 can still mean "the game would not do that".
          if (result.failed) {
            declined[step.action] = result.reply
          }
        } catch (error) {
          // One step being refused must not abandon the rest: snow out
          // of season still leaves the clouds and the downpour worth
          // setting.
          declined[step.action] =
            error instanceof ApiError
              ? (errorField(error, 'detail') ?? error.message)
              : String(error)
        }
      }

      return declined
    },
    onSuccess: (declined) => {
      setRefused(declined)

      const names = Object.keys(declined)

      if (names.length > 0) {
        toast.warning(
          t('events.presetPartly', {
            count: names.length,
            preset: chosen === null ? '' : t(`events.presets.${chosen.id}`),
          }),
        )
      } else {
        toast.success(
          t('events.presetApplied', {
            preset: chosen === null ? '' : t(`events.presets.${chosen.id}`),
          }),
        )
      }

      // Bridge 0.15 writes time and weather every ten seconds, so one
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

      <section className="space-y-3">
        <SectionMark label={t('events.weatherSet')} />

        {/* By kind rather than one flat row: ten icons in a line make
            finding "snow" a scan, and drizzle beside a blizzard implies a
            scale the two are not on. The gap between groups is wider than
            the one inside a group, so the grouping is visible without a
            rule between the rows. */}
        {PRESET_GROUPS.map((group) => (
          <PresetGroupRow
            key={group}
            group={group}
            chosen={chosen}
            usable={usable}
            onChoose={choose}
          />
        ))}
      </section>

      {/* The game's own weather stages, which a preset cannot express:
          a stage runs for a duration and the simulation drives it, where
          a preset sets values and lets them stand. */}
      <StageSection serverId={id} available={byId.get('triggerWeatherStage')?.available !== false} />

      {/* The card is wide enough for its widest step row rather than a
          fixed two columns: a label, a slider, a number and its unit stop
          fitting long before the page runs out of room. */}
      {chosen !== null && (
        <section className="w-fit min-w-full space-y-3 rounded-md border p-4 xl:min-w-3xl">
          <SectionMark label={t('events.willDo')} state={t(`events.presets.${chosen.id}`)} />

          <div className="space-y-3">
            {steps.map(({ step, action, field }) => (
              <StepRow
                key={`${step.action}.${field ?? ''}`}
                action={action}
                actionId={step.action}
                label={step.label}
                field={field}
                min={step.min}
                max={step.max}
                refused={refused[step.action]}
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
 * A weather stage, run for a duration.
 *
 * What a preset cannot express: a preset sets values and lets them
 * stand, while a stage hands the weather to the simulation for a number
 * of game hours and lets it run its own course — which is how the world
 * makes weather when nobody interferes. This is the route the game's own
 * admin console takes.
 */
function StageSection({ serverId, available }: { serverId: string; available: boolean }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const [stage, setStage] = useState<string>('storm')
  const [hours, setHours] = useState(4)

  const run = useMutation({
    mutationFn: () => triggerEvent(serverId, 'triggerWeatherStage', { stage, duration: hours }),
    onSuccess: (result) => {
      if (result.failed) {
        toast.warning(result.reply === '' ? t('events.refused') : result.reply)

        return
      }

      toast.success(t('events.stageStarted', { stage: t(`events.choices.stage.${stage}`) }))

      window.setTimeout(
        () => void queryClient.invalidateQueries({ queryKey: ['world', serverId] }),
        BRIDGE_WORLD_WRITE_SECONDS * 1000,
      )
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? (errorField(error, 'detail') ?? t('errors.generic'))
          : t('errors.generic'),
      ),
  })

  if (!available) {
    return null
  }

  return (
    <section className="w-fit min-w-full max-w-3xl space-y-3 rounded-md border p-4">
      <SectionMark label={t('events.actions.triggerWeatherStage.title')} />

      <p className="text-sm text-muted-foreground">
        {t('events.actions.triggerWeatherStage.description')}
      </p>

      <div className="flex flex-wrap items-end gap-3">
        <div className="space-y-1.5">
          <Label htmlFor="weather-stage">{t('events.fields.stage')}</Label>

          <Select value={stage} onValueChange={setStage}>
            <SelectTrigger id="weather-stage" className="w-52">
              <SelectValue />
            </SelectTrigger>

            <SelectContent>
              {WEATHER_STAGES.map((name) => (
                <SelectItem key={name} value={name}>
                  {t(`events.choices.stage.${name}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="weather-hours">{t('events.fields.duration')}</Label>

          <div className="flex items-center gap-2">
            <Input
              id="weather-hours"
              inputMode="numeric"
              className="w-20 text-center font-mono tabular-nums"
              value={String(hours)}
              onChange={(event) => {
                const typed = Number.parseInt(event.target.value, 10)

                setHours(Number.isNaN(typed) ? 4 : Math.min(240, Math.max(1, typed)))
              }}
            />

            <span className="font-mono text-xs text-muted-foreground">h</span>
          </div>
        </div>

        <Button disabled={run.isPending} onClick={() => run.mutate()}>
          <Play className="size-4" />
          {run.isPending ? t('common.loading') : t('events.startStage')}
        </Button>
      </div>
    </section>
  )
}

/** One kind of weather, as a labelled row of its own presets. */
function PresetGroupRow({
  group,
  chosen,
  usable,
  onChoose,
}: {
  group: PresetGroup
  chosen: WeatherPreset | null
  usable: (preset: WeatherPreset) => boolean
  onChoose: (preset: WeatherPreset) => void
}) {
  const { t } = useTranslation()

  return (
    <div className="space-y-1.5 pt-2 first:pt-0">
      <p className="font-mono text-[0.65rem] uppercase tracking-wider text-muted-foreground">
        {t(`events.presetGroups.${group}`)}
      </p>

      <div className="grid grid-cols-[repeat(auto-fill,minmax(7rem,1fr))] gap-2">
        {presetsOf(group).map((preset) => {
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
              onClick={() => onChoose(preset)}
            >
              <preset.icon
                className={cn('size-7', picked ? 'text-primary' : 'text-muted-foreground')}
              />
              <span className="text-xs leading-tight">{t(`events.presets.${preset.id}`)}</span>
            </button>
          )
        })}
      </div>
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
 * worse than one that admits it. And a step the game *refused* — snow in
 * July — carries the server's own answer, which is a different case from
 * one that was never offered.
 */
function StepRow({
  action,
  actionId,
  label,
  field,
  min: narrowedMin,
  max: narrowedMax,
  value,
  refused,
  onChange,
}: {
  action?: EventAction
  actionId: string
  label?: string
  field?: string
  /** The preset's own bounds, where they are narrower than the field's. */
  min?: number
  max?: number
  value?: number | boolean
  refused?: string
  onChange: (value: number) => void
}) {
  const { t } = useTranslation()

  // The preset's own wording wins where the command's name would mislead:
  // "start rain" is the command, but on the snow preset what falls is snow.
  const title =
    label === undefined
      ? t(`events.actions.${actionId}.title`, { defaultValue: actionId })
      : t(`events.stepLabels.${label}`)
  const unavailable = action?.available === false
  const declared = action?.fields.find((candidate) => candidate.name === field)

  const note = refused === undefined ? null : <RefusedNote reason={refused} />

  if (field === undefined) {
    return (
      <div className="space-y-1">
        <div className="flex items-center gap-2 text-sm">
          <span className={cn('flex-1', unavailable && 'text-muted-foreground line-through')}>
            {title}
          </span>
          {unavailable && <Badge variant="outline">{t('events.unavailable')}</Badge>}
        </div>
        {note}
      </div>
    )
  }

  // A yes-or-no step is stated, not offered: on the snow preset the
  // precipitation type IS what "snow" means, so a switch that could turn
  // it back to rain would undo the preset the operator just picked.
  if (declared?.type === 'toggle') {
    return (
      <div className="space-y-1">
        <div className="flex items-center gap-2 text-sm">
          <span className={cn('flex-1', unavailable && 'text-muted-foreground line-through')}>
            {t(`events.toggleStates.${actionId}.${value === true}`, { defaultValue: title })}
          </span>
          {unavailable && <Badge variant="outline">{t('events.unavailable')}</Badge>}
        </div>
        {note}
      </div>
    )
  }

  // A preset may narrow the field's range but never widen it: the action
  // declares what the server will accept.
  const declaredMin = typeof declared?.min === 'number' ? declared.min : 0
  const declaredMax = typeof declared?.max === 'number' ? declared.max : 100
  const min = narrowedMin === undefined ? declaredMin : Math.max(declaredMin, narrowedMin)
  const max = narrowedMax === undefined ? declaredMax : Math.min(declaredMax, narrowedMax)
  const numeric = typeof value === 'number' ? value : min
  const current = Math.min(max, Math.max(min, numeric))

  return (
    <div className={cn('space-y-2', unavailable && 'opacity-50')}>
      <div className="flex items-baseline justify-between gap-2">
        <Label htmlFor={`step-${actionId}-${field}`}>{title}</Label>

        {unavailable ? (
          <Badge variant="outline">{t('events.unavailable')}</Badge>
        ) : (
          <span className="font-mono text-xs tabular-nums text-muted-foreground">
            {formatRange(min, max, declared?.unit)}
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
            saying 80. Its unit is stated once, on the range above, rather
            than on both. */}
        <Input
          inputMode="numeric"
          aria-label={`${title} (${declared?.unit ?? ''})`.trim()}
          disabled={unavailable}
          className="w-16 shrink-0 text-center font-mono tabular-nums"
          value={String(current)}
          onChange={(event) => {
            const typed = Number.parseInt(event.target.value, 10)

            onChange(Number.isNaN(typed) ? min : Math.min(max, Math.max(min, typed)))
          }}
        />
      </div>

      {note}
    </div>
  )
}

/** What the server said when it would not do something. */
function RefusedNote({ reason }: { reason: string }) {
  const { t } = useTranslation()

  return (
    <p className="flex items-start gap-1.5 text-xs text-amber-600 dark:text-amber-500">
      <TriangleAlert aria-hidden className="mt-0.5 size-3.5 shrink-0" />
      <span>
        <span className="font-medium">{t('events.refused')}</span> {reason}
      </span>
    </p>
  )
}
