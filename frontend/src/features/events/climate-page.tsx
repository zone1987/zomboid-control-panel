import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import {
  Cloud,
  CloudFog,
  CloudRain,
  Compass,
  Droplets,
  Eye,
  Lock,
  LockOpen,
  Moon,
  Palette,
  RotateCcw,
  Sparkles,
  Sun,
  Sunrise,
  Thermometer,
  Wind,
  type LucideIcon,
} from 'lucide-react'

import { cn } from '@/lib/utils'
import { ApiError, errorField } from '@/lib/api'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Slider } from '@/components/ui/slider'
import { SectionMark } from '@/components/layout/section-mark'
import { seasonKey } from '@/features/servers/seasons'
import { triggerEvent } from './events'
import { formatRange, withUnit } from './units'
import {
  CLIMATE_COLOURS,
  CLIMATE_GROUPS,
  dialsOf,
  displayBounds,
  fromHex,
  readClimate,
  readClimateColours,
  setDial,
  toHex,
  toDisplay,
  unitOf,
  type ClimateColour,
  type ClimateColourName,
  type ClimateDial,
  type ClimateGroup,
  type ClimateReading,
  type ClimateValue,
} from './climate'

/**
 * How often the climate is re-read.
 *
 * A bridge command round trip, not a file read, so it is deliberately
 * slower than the world strip's ten seconds: the values move only when
 * the season does or somebody changes one.
 */
const POLL_MS = 20_000

/** One icon per value, so a row is findable without reading it. */
const ICONS: Record<string, LucideIcon> = {
  temperature: Thermometer,
  wind: Wind,
  windAngle: Compass,
  humidity: Droplets,
  clouds: Cloud,
  fog: CloudFog,
  precipitation: CloudRain,
  viewDistance: Eye,
  daylight: Sun,
  globalLight: Sunrise,
  nightStrength: Moon,
  ambient: Sparkles,
  desaturation: Palette,
}

/**
 * Every climate condition the world is running, and who decided it.
 *
 * The two states that matter read differently and are the reason this
 * page exists rather than thirteen more entries in the event list: the
 * game is running a value, or somebody pinned it. A pinned value holds
 * through the season change, so "why is July freezing" has an answer
 * here — and a way to undo it.
 *
 * Bounds come from the reading, never from a table in the panel. Three
 * of the thirteen do not run 0..1, and `setAdminValue` clamps silently
 * rather than refusing, so a wrong ceiling would set something other
 * than what was asked and report success.
 */
export function ClimatePage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const queryClient = useQueryClient()

  const { data, isPending, error } = useQuery({
    queryKey: ['climate', id],
    queryFn: () => readClimate(id),
    retry: false,
    refetchInterval: POLL_MS,
    refetchIntervalInBackground: false,
    placeholderData: (previous) => previous,
  })

  if (isPending) {
    return <Skeleton className="h-96 w-full" />
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold">{t('climate.title')}</h1>
        <p className="text-muted-foreground">{t('climate.description')}</p>
      </div>

      {data === undefined ? (
        <Unreachable error={error} />
      ) : (
        <>
          <Summary reading={data} serverId={id} />

          {CLIMATE_GROUPS.map((group) => (
            <DialGroup key={group} group={group} reading={data} serverId={id} />
          ))}

          <ColourSection serverId={id} />

          <ReleaseAll
            serverId={id}
            pinned={Object.values(data.values).filter((value) => value.pinned).length}
            onDone={() => void queryClient.invalidateQueries({ queryKey: ['climate', id] })}
          />
        </>
      )}
    </div>
  )
}

/**
 * Why there is nothing to show.
 *
 * The values live in the running game, so this needs the bridge — and
 * "no bridge" and "the server is down" send an operator to different
 * places, which is why the reason is printed rather than a shrug.
 */
function Unreachable({ error }: { error: unknown }) {
  const { t } = useTranslation()

  const key = error instanceof ApiError ? errorField(error, 'error') : null
  const detail = error instanceof ApiError ? errorField(error, 'detail') : null

  return (
    <section className="max-w-2xl space-y-2 rounded-md border border-dashed p-4">
      <SectionMark label={t('climate.unavailable')} />

      <p className="text-sm text-muted-foreground">
        {key === null ? t('climate.needsBridge') : t(key, { defaultValue: t('climate.needsBridge') })}
      </p>

      {detail !== null && <p className="font-mono text-xs text-muted-foreground">{detail}</p>}
    </section>
  )
}

/** The season and what is falling: the frame the thirteen values sit in. */
function Summary({ reading, serverId }: { reading: ClimateReading; serverId: string }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const temperature = reading.values.temperature
  const snowPinned = reading.precipitationIsSnow?.admin === true

  const release = useMutation({
    mutationFn: () => triggerEvent(serverId, 'releaseSnow', {}),
    onSuccess: () => {
      toast.success(t('climate.released'))
      void queryClient.invalidateQueries({ queryKey: ['climate', serverId] })
    },
    onError: () => toast.error(t('errors.generic')),
  })

  return (
    <section className="space-y-2">
      <SectionMark label={t('climate.now')} state={t('events.live')} />

      <div className="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-md border p-3 text-sm">
        <Fact icon={Sun} label={t('climate.season')}>
          {/* The game names seasons in English; anything unlisted falls
              back to what the server said. */}
          {reading.season === ''
            ? '—'
            : t(`world.seasonName.${seasonKey(reading.season)}`, {
                defaultValue: reading.season,
              })}
        </Fact>

        {temperature !== undefined && (
          <Fact icon={Thermometer} label={t('events.actions.setTemperature.title')}>
            {withUnit(toDisplay({ name: 'temperature', scale: 'celsius', group: 'air' }, temperature.value, reading.maxWindSpeedKph), '°C')}
          </Fact>
        )}

        <Fact icon={Wind} label={t('events.fields.value')}>
          {withUnit(Math.round(reading.windSpeedKph), 'km/h')}
        </Fact>

        <Fact icon={CloudRain} label={t('climate.falling')}>
          {reading.snowing
            ? t('world.snowing')
            : reading.raining
              ? t('world.raining')
              : t('climate.nothing')}
        </Fact>

        {reading.thunderStorming && <Badge variant="outline">{t('climate.thunder')}</Badge>}

        {/* A pinned snow flag outlives the season, which is exactly the
            thing somebody comes here to undo. */}
        {snowPinned && (
          <div className="ml-auto flex items-center gap-2">
            <Badge variant="secondary" className="gap-1">
              <Lock className="size-3" />
              {t('climate.snowPinned')}
            </Badge>

            <Button
              variant="outline"
              size="sm"
              disabled={release.isPending}
              onClick={() => release.mutate()}
            >
              <LockOpen className="size-3.5" />
              {t('climate.release')}
            </Button>
          </div>
        )}
      </div>
    </section>
  )
}

function Fact({
  icon: Icon,
  label,
  children,
}: {
  icon: LucideIcon
  label: string
  children: React.ReactNode
}) {
  return (
    <span className="flex items-center gap-1.5" title={label}>
      <Icon aria-hidden className="size-4 text-muted-foreground" />
      <span className="font-mono tabular-nums">{children}</span>
    </span>
  )
}

/** One themed group of values. */
function DialGroup({
  group,
  reading,
  serverId,
}: {
  group: ClimateGroup
  reading: ClimateReading
  serverId: string
}) {
  const { t } = useTranslation()

  const dials = useMemo(
    () => dialsOf(group).filter((dial) => reading.values[dial.name] !== undefined),
    [group, reading],
  )

  if (dials.length === 0) {
    return null
  }

  return (
    <section className="w-fit min-w-full space-y-3 rounded-md border p-4 xl:min-w-3xl">
      <SectionMark label={t(`climate.groups.${group}`)} />

      <div className="space-y-4">
        {dials.map((dial) => (
          <DialRow
            key={dial.name}
            dial={dial}
            value={reading.values[dial.name] as ClimateValue}
            maxWindKph={reading.maxWindSpeedKph}
            serverId={serverId}
          />
        ))}
      </div>
    </section>
  )
}

/**
 * One value: what it is, who set it, and a way to change or release it.
 *
 * A value with no action is shown and not offered — knowing the game is
 * running desaturation at 40 % is worth having even where the panel
 * cannot set it, and hiding what cannot be changed is how a panel comes
 * to misrepresent the world.
 */
function DialRow({
  dial,
  value,
  maxWindKph,
  serverId,
}: {
  dial: ClimateDial
  value: ClimateValue
  maxWindKph: number
  serverId: string
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const { min, max } = displayBounds(dial, value, maxWindKph)
  const unit = unitOf(dial)
  const shown = toDisplay(dial, value.value, maxWindKph, value.max)

  // Held locally while being dragged, and let go whenever the server's
  // own reading moves -- otherwise a poll mid-drag would yank the handle,
  // and a stale draft would keep showing a value nobody set any more.
  // Derived during render rather than synchronised in an effect: the
  // reading is the truth, the draft is only an unsent edit of it.
  const [edit, setEdit] = useState<{ from: number; to: number } | null>(null)
  const draft = edit !== null && edit.from === shown ? edit.to : shown

  const setDraft = (next: number) => setEdit({ from: shown, to: next })

  const Icon = ICONS[dial.name] ?? Sparkles
  const settable = dial.action !== undefined

  const apply = useMutation({
    mutationFn: () => setDial(serverId, dial, draft),
    onSuccess: () => {
      toast.success(t('climate.set', { name: t(`climate.values.${dial.name}`) }))
      // The edit is spent: the next reading is what the value is now.
      setEdit(null)
      void queryClient.invalidateQueries({ queryKey: ['climate', serverId] })
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? (errorField(error, 'detail') ?? t('errors.generic')) : t('errors.generic'),
      ),
  })

  const release = useMutation({
    mutationFn: () => triggerEvent(serverId, 'releaseClimate', { name: dial.name }),
    onSuccess: () => {
      toast.success(t('climate.released'))
      void queryClient.invalidateQueries({ queryKey: ['climate', serverId] })
    },
    onError: () => toast.error(t('errors.generic')),
  })

  const changed = Math.abs(draft - shown) > (dial.scale === 'raw' ? 0.001 : 0.05)

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <Label
          htmlFor={`climate-${dial.name}`}
          className="flex items-center gap-2 text-sm font-medium"
        >
          <Icon aria-hidden className="size-4 text-muted-foreground" />
          {t(`climate.values.${dial.name}`)}
        </Label>

        {/* Who decided this value, which is the question the page answers. */}
        {value.pinned ? (
          <Badge variant="secondary" className="gap-1">
            <Lock className="size-3" />
            {t('climate.pinned')}
          </Badge>
        ) : (
          <span className="text-xs text-muted-foreground">{t('climate.byTheGame')}</span>
        )}

        <span className="ml-auto font-mono text-xs tabular-nums text-muted-foreground">
          {formatRange(min, max, unit)}
        </span>
      </div>

      <div className="flex items-center gap-3">
        <Slider
          id={`climate-${dial.name}`}
          min={min}
          max={max}
          step={dial.scale === 'raw' ? 0.01 : dial.scale === 'celsius' ? 0.5 : 1}
          value={[Math.min(max, Math.max(min, draft))]}
          disabled={!settable}
          aria-label={t(`climate.values.${dial.name}`)}
          onValueChange={([next]) => setDraft(next ?? min)}
        />

        <Input
          inputMode="decimal"
          aria-label={`${t(`climate.values.${dial.name}`)} (${unit ?? ''})`.trim()}
          disabled={!settable}
          className="w-20 shrink-0 text-center font-mono tabular-nums"
          value={String(draft)}
          onChange={(event) => {
            const typed = Number.parseFloat(event.target.value)

            setDraft(Number.isNaN(typed) ? min : Math.min(max, Math.max(min, typed)))
          }}
        />

        <div className="flex w-40 shrink-0 items-center gap-1">
          {settable && (
            <Button
              size="sm"
              variant={changed ? 'default' : 'ghost'}
              disabled={!changed || apply.isPending}
              onClick={() => apply.mutate()}
            >
              {t('climate.apply')}
            </Button>
          )}

          {/* Only a pinned value has something to release. */}
          {value.pinned && (
            <Button
              size="sm"
              variant="ghost"
              title={t('climate.releaseHint')}
              disabled={release.isPending}
              onClick={() => release.mutate()}
            >
              <LockOpen className="size-3.5" />
            </Button>
          )}
        </div>
      </div>

      {!settable && (
        <p className="text-xs text-muted-foreground">{t('climate.readOnly')}</p>
      )}
    </div>
  )
}

/**
 * The two colours the game keeps: the global light and the fog.
 *
 * One colour picker each, set for indoors and out together. The game
 * holds eight channels — four in, four out — but "the light is too blue"
 * is one thought, and a panel offering eight sliders for it would be a
 * panel nobody uses. Anyone who needs them apart has the console.
 */
function ColourSection({ serverId }: { serverId: string }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const { data, error } = useQuery({
    queryKey: ['climate-colours', serverId],
    queryFn: () => readClimateColours(serverId),
    retry: false,
  })

  if (error !== null && error !== undefined) {
    return null
  }

  return (
    <section className="w-fit min-w-full space-y-3 rounded-md border p-4 xl:min-w-3xl">
      <SectionMark label={t('climate.colours')} />

      <p className="text-sm text-muted-foreground">{t('climate.coloursHint')}</p>

      {data === undefined ? (
        <Skeleton className="h-20 w-full" />
      ) : (
        CLIMATE_COLOURS.map((name) => (
          <ColourRow
            key={name}
            serverId={serverId}
            name={name}
            colour={data.colours[name]}
            onDone={() =>
              void queryClient.invalidateQueries({ queryKey: ['climate-colours', serverId] })
            }
          />
        ))
      )}
    </section>
  )
}

function ColourRow({
  serverId,
  name,
  colour,
  onDone,
}: {
  serverId: string
  name: ClimateColourName
  colour: ClimateColour | undefined
  onDone: () => void
}) {
  const { t } = useTranslation()

  // What the game is showing, which is what the picker starts from.
  const current = toHex(colour?.value?.exterior)
  const [edit, setEdit] = useState<{ from: string; to: string } | null>(null)
  const shown = edit !== null && edit.from === current ? edit.to : current

  const apply = useMutation({
    mutationFn: () => triggerEvent(serverId, 'setClimateColour', { name, ...fromHex(shown) }),
    onSuccess: () => {
      toast.success(t('climate.colourSet'))
      setEdit(null)
      onDone()
    },
    onError: () => toast.error(t('errors.generic')),
  })

  const release = useMutation({
    mutationFn: () => triggerEvent(serverId, 'releaseClimateColour', { name }),
    onSuccess: () => {
      toast.success(t('climate.released'))
      setEdit(null)
      onDone()
    },
    onError: () => toast.error(t('errors.generic')),
  })

  const pinned = colour?.admin === true

  return (
    <div className="flex flex-wrap items-center gap-3">
      <Label htmlFor={`colour-${name}`} className="flex items-center gap-2 text-sm font-medium">
        <Palette aria-hidden className="size-4 text-muted-foreground" />
        {t(`climate.colourNames.${name}`)}
      </Label>

      {pinned ? (
        <Badge variant="secondary" className="gap-1">
          <Lock className="size-3" />
          {t('climate.pinned')}
        </Badge>
      ) : (
        <span className="text-xs text-muted-foreground">{t('climate.byTheGame')}</span>
      )}

      <input
        id={`colour-${name}`}
        type="color"
        value={shown}
        aria-label={t(`climate.colourNames.${name}`)}
        className="h-9 w-16 shrink-0 cursor-pointer rounded-md border bg-transparent"
        onChange={(event) => setEdit({ from: current, to: event.target.value })}
      />

      <span className="font-mono text-xs tabular-nums text-muted-foreground">{shown}</span>

      <div className="ml-auto flex gap-1">
        <Button
          size="sm"
          variant={shown === current ? 'ghost' : 'default'}
          disabled={shown === current || apply.isPending}
          onClick={() => apply.mutate()}
        >
          {t('climate.apply')}
        </Button>

        {pinned && (
          <Button
            size="sm"
            variant="ghost"
            title={t('climate.releaseHint')}
            disabled={release.isPending}
            onClick={() => release.mutate()}
          >
            <LockOpen className="size-3.5" />
          </Button>
        )}
      </div>
    </div>
  )
}

/** Hands every pinned value back to the game at once. */
function ReleaseAll({
  serverId,
  pinned,
  onDone,
}: {
  serverId: string
  pinned: number
  onDone: () => void
}) {
  const { t } = useTranslation()

  const reset = useMutation({
    mutationFn: () => triggerEvent(serverId, 'resetClimate', {}),
    onSuccess: () => {
      toast.success(t('climate.resetDone'))
      onDone()
    },
    onError: () => toast.error(t('errors.generic')),
  })

  return (
    <section
      className={cn(
        'flex max-w-2xl flex-wrap items-center gap-3 rounded-md border p-4',
        pinned === 0 && 'border-dashed',
      )}
    >
      <div className="flex-1">
        <p className="text-sm font-medium">{t('climate.resetTitle')}</p>
        <p className="text-xs text-muted-foreground">
          {pinned === 0 ? t('climate.nonePinned') : t('climate.pinnedCount', { count: pinned })}
        </p>
      </div>

      <Button
        variant="outline"
        disabled={pinned === 0 || reset.isPending}
        onClick={() => reset.mutate()}
      >
        <RotateCcw className="size-4" />
        {t('climate.reset')}
      </Button>
    </section>
  )
}
