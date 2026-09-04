import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { CalendarDays, Clock, Cloud, CloudRain, Snowflake, Sun, Thermometer, Wind } from 'lucide-react'

import { getWorld } from './world'

const MONTHS_DE = 12

/**
 * Time, weather and capacity, shown above the player list. Nothing is
 * rendered when the server still runs a bridge older than 0.4.0, which
 * writes no such file.
 */
export function WorldStrip({ serverId }: { serverId: string }) {
  const { t, i18n } = useTranslation()

  const { data } = useQuery({
    queryKey: ['world', serverId],
    queryFn: () => getWorld(serverId),
    retry: false,
    refetchInterval: 30_000,
    refetchIntervalInBackground: true,
    placeholderData: (previous) => previous,
  })

  if (data == null || (data.gameTime === null && data.weather === null)) {
    return null
  }

  const { gameTime, weather } = data

  return (
    <div className="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-md border bg-muted/30 px-4 py-3 text-sm">
      {gameTime !== null && (
        <>
          <Fact icon={<CalendarDays className="size-4" />} label={t('world.date')}>
            {formatDate(gameTime, i18n.language)}
          </Fact>

          <Fact icon={<Clock className="size-4" />} label={t('world.time')}>
            {String(gameTime.hour).padStart(2, '0')}:{String(gameTime.minute).padStart(2, '0')}
          </Fact>

          <Fact icon={<Sun className="size-4" />} label={t('world.daysSurvived')}>
            {gameTime.daysSurvived}
          </Fact>
        </>
      )}

      {weather !== null && (
        <>
          <Fact icon={<Thermometer className="size-4" />} label={t('world.temperature')}>
            {weather.temperature.toLocaleString(i18n.language, {
              minimumFractionDigits: 1,
              maximumFractionDigits: 1,
            })}{' '}
            °C
          </Fact>

          <Fact icon={weatherIcon(weather.raining, weather.snowing)} label={t('world.conditions')}>
            {weather.snowing
              ? t('world.snowing')
              : weather.raining
                ? t('world.raining')
                : t('world.clear')}
          </Fact>

          <Fact icon={<Wind className="size-4" />} label={t('world.wind')}>
            {weather.windSpeed.toLocaleString(i18n.language, {
              minimumFractionDigits: 1,
              maximumFractionDigits: 1,
            })}{' '}
            km/h
          </Fact>

          <Fact icon={<Cloud className="size-4" />} label={t('world.season')}>
            {/* The game names seasons in English; anything unlisted falls
                back to what the server said. */}
            {t(`world.seasonName.${seasonKey(weather.season)}`, { defaultValue: weather.season })}
          </Fact>
        </>
      )}
    </div>
  )
}

/** "Early Summer" becomes "earlySummer". */
function seasonKey(season: string): string {
  const words = season.trim().split(/\s+/)

  return words
    .map((word, index) =>
      index === 0
        ? word.toLowerCase()
        : word.charAt(0).toUpperCase() + word.slice(1).toLowerCase(),
    )
    .join('')
}

function weatherIcon(raining: boolean, snowing: boolean) {
  if (snowing) {
    return <Snowflake className="size-4" />
  }

  return raining ? <CloudRain className="size-4" /> : <Sun className="size-4" />
}

function Fact({
  icon,
  label,
  children,
}: {
  icon: React.ReactNode
  label: string
  children: React.ReactNode
}) {
  return (
    <div className="flex items-center gap-2" title={label}>
      <span className="text-muted-foreground">{icon}</span>
      <span className="font-medium">{children}</span>
    </div>
  )
}

/** The in-game calendar is a real one, so the browser can format it. */
function formatDate(time: GameTimeShape, locale: string): string {
  if (time.month < 1 || time.month > MONTHS_DE) {
    return `${time.day}.${time.month}.${time.year}`
  }

  return new Date(time.year, time.month - 1, time.day).toLocaleDateString(locale, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

type GameTimeShape = { year: number; month: number; day: number }
