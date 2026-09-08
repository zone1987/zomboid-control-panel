import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Search } from 'lucide-react'

import { cn } from '@/lib/utils'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import { SectionMark } from '@/components/layout/section-mark'
import type { Ban, Player } from './players'

type Scope = 'online' | 'all' | 'banned'

/**
 * Who there is to act on, as a list rather than a table.
 *
 * A five-column table wants the whole width and got a third of it, which
 * is what made the dossier beside it a slit. A name, a state dot and one
 * line of context is all that choosing somebody needs — the rest of what
 * the table showed is the dossier's job, and showing it twice is what
 * squeezed both.
 *
 * The manual field at the bottom is the reason this is not just the
 * roster: somebody who has never connected, or whose name is misspelled
 * in a report, still has to be bannable.
 */
export function PlayerList({
  players,
  bans,
  selected,
  onSelect,
  onManual,
}: {
  players: Player[]
  bans: Ban[]
  selected: string | null
  onSelect: (player: Player) => void
  onManual: (username: string) => void
}) {
  const { t, i18n } = useTranslation()

  const [scope, setScope] = useState<Scope>('all')
  const [term, setTerm] = useState('')
  const [typed, setTyped] = useState('')

  const online = players.filter((player) => player.online)

  const counts = {
    online: online.length,
    all: players.length,
    banned: bans.length,
  }

  const shown = useMemo(() => {
    const pool = scope === 'online' ? online : players
    const needle = term.trim().toLowerCase()

    const matched =
      needle === ''
        ? pool
        : pool.filter((player) => player.username.toLowerCase().includes(needle))

    // Online first, then by who was seen most recently: the people worth
    // acting on are almost always at one of those two ends.
    return [...matched].sort((left, right) => {
      if (left.online !== right.online) {
        return left.online ? -1 : 1
      }

      return right.lastSeenAt.localeCompare(left.lastSeenAt)
    })
  }, [online, players, scope, term])

  const bannedNames = new Set(bans.map((ban) => ban.username.toLowerCase()))

  return (
    <div className="flex h-full flex-col gap-3 rounded-md border p-3">
      <SectionMark label={t('players.roster')} state={String(counts.all)} />

      <div className="flex flex-wrap gap-1" role="tablist">
        {(['online', 'all', 'banned'] as const).map((option) => (
          <button
            key={option}
            type="button"
            role="tab"
            aria-selected={scope === option}
            className={cn(
              // py-2 on a phone: py-1 leaves a 24px target, under what a
              // finger reliably hits.
              'flex items-center gap-1.5 rounded-md px-2.5 py-2 text-xs transition-colors sm:py-1',
              scope === option
                ? 'bg-secondary font-medium text-secondary-foreground'
                : 'text-muted-foreground hover:text-foreground',
            )}
            onClick={() => setScope(option)}
          >
            {t(`players.scope.${option}`)}
            <span className="font-mono tabular-nums opacity-60">{counts[option]}</span>
          </button>
        ))}
      </div>

      <div className="relative">
        <Search
          aria-hidden
          className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
        />
        <Input
          className="pl-8"
          value={term}
          placeholder={t('players.searchList')}
          aria-label={t('players.searchList')}
          onChange={(event) => setTerm(event.target.value)}
        />
      </div>

      {scope === 'banned' ? (
        <BannedRows bans={bans} />
      ) : (
        <div className="-mx-1 min-h-0 flex-1 space-y-0.5 overflow-y-auto px-1">
          {shown.length === 0 ? (
            <p className="px-2 py-6 text-center text-sm text-muted-foreground">
              {term === '' ? t('players.none') : t('players.noMatch')}
            </p>
          ) : (
            shown.map((player) => (
              <button
                key={player.username}
                type="button"
                aria-current={selected === player.username}
                className={cn(
                  'w-full rounded-md border border-transparent px-2.5 py-2 text-left transition-colors',
                  selected === player.username
                    ? 'border-primary/40 bg-primary/5'
                    : 'hover:bg-muted/50',
                )}
                onClick={() => onSelect(player)}
              >
                <div className="flex items-baseline gap-2">
                  <span
                    aria-hidden
                    className={cn(
                      'size-2 shrink-0 translate-y-[-1px] rounded-full',
                      player.online ? 'bg-emerald-500' : 'bg-muted-foreground/40',
                    )}
                  />

                  <span className="min-w-0 flex-1 truncate text-sm font-medium">
                    {player.username}
                  </span>

                  {bannedNames.has(player.username.toLowerCase()) && (
                    <Badge variant="destructive" className="px-1 py-0 text-[10px]">
                      {t('players.bannedShort')}
                    </Badge>
                  )}
                </div>

                {/* One line of context, so the list ranks without
                    becoming the table it replaced. */}
                <div className="mt-0.5 flex items-baseline gap-2 pl-4 text-xs text-muted-foreground">
                  <span className="truncate">
                    {player.online
                      ? t('players.online')
                      : new Date(player.lastSeenAt).toLocaleDateString(i18n.language)}
                  </span>

                  {player.hoursSurvived !== null && (
                    <span className="ml-auto shrink-0 font-mono tabular-nums">
                      {t('players.hoursValue', { hours: Math.round(player.hoursSurvived) })}
                    </span>
                  )}
                </div>
              </button>
            ))
          )}
        </div>
      )}

      {/* Somebody who never connected still has to be actionable. */}
      <Collapsible>
        {/* py-2 on a phone: the bare text line is a 17px target. */}
        <CollapsibleTrigger className="w-full py-2 text-left font-mono text-[11px] tracking-wide text-muted-foreground uppercase hover:text-foreground sm:py-0">
          › {t('players.manualTarget')}
        </CollapsibleTrigger>

        <CollapsibleContent className="pt-2">
          <Label htmlFor="manual-target" className="sr-only">
            {t('players.manualTarget')}
          </Label>

          <Input
            id="manual-target"
            className="font-mono"
            value={typed}
            placeholder={t('players.username')}
            onChange={(event) => setTyped(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter' && typed.trim() !== '') {
                onManual(typed.trim())
              }
            }}
            onBlur={() => {
              if (typed.trim() !== '') {
                onManual(typed.trim())
              }
            }}
          />
        </CollapsibleContent>
      </Collapsible>
    </div>
  )
}

function BannedRows({ bans }: { bans: Ban[] }) {
  const { t, i18n } = useTranslation()

  if (bans.length === 0) {
    return (
      <p className="min-h-0 flex-1 px-2 py-6 text-center text-sm text-muted-foreground">
        {t('players.noBans')}
      </p>
    )
  }

  return (
    <div className="-mx-1 min-h-0 flex-1 space-y-1 overflow-y-auto px-1">
      {bans.map((ban) => (
        <div key={ban.username} className="rounded-md border px-2.5 py-2">
          <p className="truncate text-sm font-medium">{ban.username}</p>

          <p className="text-xs text-muted-foreground">
            {ban.permanent
              ? t('players.permanent')
              : ban.expiresAt !== null
                ? t('players.until', {
                    when: new Date(ban.expiresAt).toLocaleDateString(i18n.language),
                  })
                : '—'}
          </p>

          {ban.reason !== null && ban.reason !== '' && (
            <p className="mt-0.5 truncate text-xs text-muted-foreground italic">{ban.reason}</p>
          )}
        </div>
      ))}
    </div>
  )
}
