import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { readPlayerHistory } from './players'
import { reasonKey } from './reason'

export function PlayerHistory({
  serverId,
  username,
}: {
  serverId: string
  username: string
}) {
  const { t, i18n } = useTranslation()

  const { data, isPending } = useQuery({
    queryKey: ['player-history', serverId, username],
    queryFn: () => readPlayerHistory(serverId, username),
    retry: false,
  })

  if (isPending) {
    return <Skeleton className="h-32 w-full" />
  }

  const items = data?.items ?? []

  if (items.length === 0) {
    return <p className="text-sm text-muted-foreground">{t('players.noHistoryForPlayer')}</p>
  }

  return (
    <ul className="divide-y">
      {items.map((entry, index) => (
        <li key={`${entry.performedAt}-${index}`} className="flex flex-wrap gap-x-3 gap-y-1 py-2 text-sm">
          <Badge variant="outline" className="shrink-0">
            {t(`players.actionName.${entry.action}`, { defaultValue: entry.action })}
          </Badge>

          <span className="min-w-0 flex-1">
            {/* A reason is prose or a key: the backend writes
                `players.joined` for a join, and printing that verbatim is
                what the history did until now. */}
            {entry.reason !== null && entry.reason !== '' && (
              <span className="text-muted-foreground">
                {t(reasonKey(entry.reason) ?? entry.reason, { defaultValue: entry.reason })}
              </span>
            )}
          </span>

          <span className="shrink-0 text-xs text-muted-foreground">
            {entry.performedBy ?? t('players.byTheSystem')}
            {' · '}
            {new Date(entry.performedAt).toLocaleString(i18n.language)}
          </span>
        </li>
      ))}
    </ul>
  )
}
