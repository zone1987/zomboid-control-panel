import { reasonKey } from './reason'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { History } from 'lucide-react'

import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { listHistory } from './players'

const ACTION_VARIANT: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
  kick: 'secondary',
  ban: 'destructive',
  unban: 'default',
  access_level: 'outline',
}

export function ModerationHistory({ serverId }: { serverId: string }) {
  const { t, i18n } = useTranslation()

  const { data, isPending } = useQuery({
    queryKey: ['moderation-history', serverId],
    queryFn: () => listHistory(serverId),
  })

  if (isPending) {
    return <Skeleton className="h-48 w-full" />
  }

  const entries = data?.items ?? []

  if (entries.length === 0) {
    return (
      <Empty>
        <EmptyHeader>
          <EmptyMedia variant="icon">
            <History />
          </EmptyMedia>
          <EmptyTitle>{t('players.noHistory')}</EmptyTitle>
          <EmptyDescription>{t('players.noHistoryHint')}</EmptyDescription>
        </EmptyHeader>
      </Empty>
    )
  }

  return (
    <div className="overflow-x-auto rounded-md border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{t('players.action')}</TableHead>
            <TableHead>{t('players.player')}</TableHead>
            <TableHead>{t('players.reason')}</TableHead>
            <TableHead>{t('players.performedBy')}</TableHead>
            <TableHead>{t('players.performedAt')}</TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          {entries.map((entry, index) => (
            <TableRow key={`${entry.performedAt}-${index}`}>
              <TableCell>
                <Badge variant={ACTION_VARIANT[entry.action] ?? 'outline'}>
                  {t(`players.actionName.${entry.action}`)}
                </Badge>
              </TableCell>

              <TableCell className="font-medium">{entry.username}</TableCell>

              {/* The one free-text column, so the only one that must
                  wrap: shadcn's TableCell is whitespace-nowrap, which
                  made a long reason widen the table instead. */}
              <TableCell className="max-w-md text-sm whitespace-normal break-words text-muted-foreground">
                {entry.reason === null
                  ? t('common.none')
                  : t(reasonKey(entry.reason) ?? entry.reason, {
                      defaultValue: entry.reason,
                    })}
              </TableCell>

              <TableCell className="text-sm text-muted-foreground">
                {entry.performedBy ?? t('common.unknown')}
              </TableCell>

              <TableCell className="text-sm text-muted-foreground">
                {new Date(entry.performedAt).toLocaleString(i18n.language)}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}
