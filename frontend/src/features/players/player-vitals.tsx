import { useTranslation } from 'react-i18next'

import { Badge } from '@/components/ui/badge'
import type { Player } from './players'

/**
 * What the bridge already reports about a character.
 *
 * The facts and the traits. The skills have their own tab: buried at
 * the bottom of this one, nobody found them.
 */
export function PlayerVitals({ player }: { player: Player }) {
  const { t, i18n } = useTranslation()

  const format = (value: string) => new Date(value).toLocaleString(i18n.language)

  return (
        <div className="space-y-5">
          <dl className="grid grid-cols-2 gap-3 text-sm">
            <Fact label={t('players.health')}>
              {player.health === null ? '—' : `${Math.round(player.health * 100)}%`}
            </Fact>

            <Fact label={t('players.infection')}>
              {player.infected
                ? player.infectionLevel === null
                  ? t('players.infected')
                  : `${Math.round(player.infectionLevel * 100)}%`
                : t('players.notInfected')}
            </Fact>

            <Fact label={t('players.survivalTime')}>
              {player.hoursSurvived === null
                ? '—'
                : t('players.hoursValue', { hours: Math.round(player.hoursSurvived) })}
            </Fact>

            <Fact label={t('players.accessLevel')}>
              {t(`players.level.${player.accessLevel ?? 'none'}`)}
            </Fact>

            <Fact label={t('players.position')}>
              {player.position.x === null
                ? '—'
                : `${Math.round(player.position.x)}, ${Math.round(player.position.y ?? 0)}, ${Math.round(player.position.z ?? 0)}`}
            </Fact>

            <Fact label={t('players.firstSeen')}>{format(player.firstSeenAt)}</Fact>
          </dl>

          <section className="space-y-2">
            <h3 className="text-sm font-medium">{t('players.traits')}</h3>

            {(player.traits ?? []).length === 0 ? (
              <p className="text-sm text-muted-foreground">{t('players.noTraits')}</p>
            ) : (
              <div className="flex flex-wrap gap-1.5">
                {/* The game's own name, not the script id: this tab
                    showed `slowlearner` where the character tab reads
                    "Langsam-Lerner". */}
                {(player.traits ?? []).map((trait) => (
                  <Badge key={trait} variant="secondary" className="text-xs">
                    {t(`character.trait.${trait}`, { defaultValue: trait })}
                  </Badge>
                ))}
              </div>
            )}
          </section>
        </div>
  )
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="font-medium">{children}</dd>
    </div>
  )
}
