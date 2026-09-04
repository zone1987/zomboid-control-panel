import { useTranslation } from 'react-i18next'

import { Badge } from '@/components/ui/badge'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import type { Player } from './players'

export function PlayerDetail({
  player,
  open,
  onOpenChange,
}: {
  player: Player
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const { t, i18n } = useTranslation()

  const skills = Object.entries(player.skills ?? {})
    .filter(([, level]) => level > 0)
    .sort(([, a], [, b]) => b - a)

  const format = (value: string) => new Date(value).toLocaleString(i18n.language)

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{player.username}</DialogTitle>
          <DialogDescription>
            {player.online ? t('players.online') : t('players.offline')}
            {player.steamId && ` · ${player.steamId}`}
          </DialogDescription>
        </DialogHeader>

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

            <Fact label={t('players.survived', { hours: '' }).trim()}>
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
            <h3 className="text-sm font-medium">{t('players.skills')}</h3>

            {skills.length === 0 ? (
              <p className="text-sm text-muted-foreground">{t('players.noSkills')}</p>
            ) : (
              <div className="space-y-1.5">
                {skills.map(([name, level]) => (
                  <div key={name} className="flex items-center gap-3">
                    <span className="w-32 shrink-0 truncate text-sm">{name}</span>

                    <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                      <div
                        className="h-full rounded-full bg-primary"
                        style={{ width: `${Math.min(100, (level / 10) * 100)}%` }}
                      />
                    </div>

                    <span className="w-6 shrink-0 text-right text-sm tabular-nums">{level}</span>
                  </div>
                ))}
              </div>
            )}
          </section>

          <section className="space-y-2">
            <h3 className="text-sm font-medium">{t('players.traits')}</h3>

            {(player.traits ?? []).length === 0 ? (
              <p className="text-sm text-muted-foreground">{t('players.noTraits')}</p>
            ) : (
              <div className="flex flex-wrap gap-1.5">
                {(player.traits ?? []).map((trait) => (
                  <Badge key={trait} variant="secondary" className="text-xs">
                    {trait}
                  </Badge>
                ))}
              </div>
            )}
          </section>
        </div>
      </DialogContent>
    </Dialog>
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
