import { useTranslation } from 'react-i18next'
import { Clock, LogOut, Ban as BanIcon, Skull, Swords } from 'lucide-react'

import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Copyable } from '@/components/ui/copyable'
import type { Player } from './players'

/**
 * Who is being looked at, and the two acts that must always be one click.
 *
 * Kick and ban sit here rather than only in the list's row menu: once a
 * dossier is open, going back to the row to act on the same person is
 * the modal problem in a different shape.
 */
export function DossierHeader({
  player,
  pending,
  onKick,
  onBan,
}: {
  player: Player
  pending: boolean
  onKick: () => void
  onBan: () => void
}) {
  const { t, i18n } = useTranslation()

  const facts: { icon: typeof Clock; label: string; value: string }[] = []

  if (player.hoursSurvived !== null) {
    facts.push({
      icon: Clock,
      label: t('players.survivalTime'),
      value: t('players.hoursValue', { hours: Math.round(player.hoursSurvived) }),
    })
  }

  if (player.zombieKills !== null && player.zombieKills !== undefined) {
    facts.push({
      icon: Skull,
      label: t('players.zombieKills'),
      value: String(player.zombieKills),
    })
  }

  if (player.survivorKills !== null && player.survivorKills !== undefined && player.survivorKills > 0) {
    facts.push({
      icon: Swords,
      label: t('players.survivorKills'),
      value: String(player.survivorKills),
    })
  }

  return (
    <div
      className={cn(
        'rounded-md border p-4',
        // The accent says "this is the one selected" without relying on
        // colour alone: the border is a shape as well as a hue.
        'border-l-2 border-l-primary/60',
      )}
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <span
              aria-hidden
              className={cn(
                'size-2.5 shrink-0 rounded-full',
                player.online ? 'bg-emerald-500' : 'bg-muted-foreground/40',
              )}
            />

            <h2 className="truncate text-xl font-semibold">{player.username}</h2>

            <span className="text-sm text-muted-foreground">
              {player.online
                ? t('players.online')
                : t('players.lastSeenAt', {
                    when: new Date(player.lastSeenAt).toLocaleString(i18n.language),
                  })}
            </span>
          </div>

          {player.steamId !== null && player.steamId !== '' && (
            <Copyable value={player.steamId} className="mt-1.5" />
          )}
        </div>

        <div className="flex shrink-0 gap-2">
          <Button variant="outline" size="sm" disabled={!player.online || pending} onClick={onKick}>
            <LogOut className="size-4" />
            {t('players.kick')}
          </Button>

          <Button variant="outline" size="sm" disabled={pending} onClick={onBan}>
            <BanIcon className="size-4 text-destructive" />
            {t('players.ban')}
          </Button>
        </div>
      </div>

      {facts.length > 0 && (
        <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 border-t pt-3">
          {facts.map((fact) => (
            <div key={fact.label} className="flex items-baseline gap-1.5">
              <fact.icon aria-hidden className="size-3.5 translate-y-0.5 text-muted-foreground" />
              <dt className="text-xs text-muted-foreground">{fact.label}</dt>
              <dd className="font-mono text-sm tabular-nums">{fact.value}</dd>
            </div>
          ))}
        </dl>
      )}
    </div>
  )
}
