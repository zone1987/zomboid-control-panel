import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ShieldOff, Undo2 } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { listBans, unbanPlayer } from './players'

export function BanList({ serverId }: { serverId: string }) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()

  const { data, isPending } = useQuery({
    queryKey: ['bans', serverId],
    queryFn: () => listBans(serverId),
  })

  const unban = useMutation({
    mutationFn: (username: string) => unbanPlayer(serverId, username),
    onSuccess: async (result, username) => {
      await queryClient.invalidateQueries({ queryKey: ['bans', serverId] })
      await queryClient.invalidateQueries({ queryKey: ['players', serverId] })
      toast.success(t('players.unbanned', { username }), {
        description: result.reply === '' ? undefined : result.reply,
      })
    },
    onError: () => toast.error(t('players.commandFailed')),
  })

  if (isPending) {
    return <Skeleton className="h-48 w-full" />
  }

  const bans = data?.items ?? []
  const format = (value: string) => new Date(value).toLocaleString(i18n.language)

  return (
    <div className="space-y-4">
      <Alert>
        <AlertDescription>{t('players.banListNote')}</AlertDescription>
      </Alert>

      {bans.length === 0 ? (
        <Empty>
          <EmptyHeader>
            <EmptyMedia variant="icon">
              <ShieldOff />
            </EmptyMedia>
            <EmptyTitle>{t('players.noBans')}</EmptyTitle>
            <EmptyDescription>{t('players.noBansHint')}</EmptyDescription>
          </EmptyHeader>
        </Empty>
      ) : (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{t('players.player')}</TableHead>
                <TableHead>{t('players.reason')}</TableHead>
                <TableHead>{t('players.bannedAt')}</TableHead>
                <TableHead>{t('players.until')}</TableHead>
                <TableHead className="w-24 text-right">{t('common.actions')}</TableHead>
              </TableRow>
            </TableHeader>

            <TableBody>
              {bans.map((ban) => (
                <TableRow key={ban.username}>
                  <TableCell className="font-medium">{ban.username}</TableCell>

                  <TableCell className="text-sm text-muted-foreground">
                    {ban.reason ?? t('common.none')}
                  </TableCell>

                  <TableCell className="text-sm text-muted-foreground">
                    {format(ban.bannedAt)}
                  </TableCell>

                  <TableCell>
                    {ban.permanent ? (
                      <Badge variant="destructive">{t('players.duration.permanent')}</Badge>
                    ) : (
                      <span className="text-sm">{format(ban.expiresAt as string)}</span>
                    )}
                  </TableCell>

                  <TableCell className="text-right">
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={unban.isPending}
                      onClick={() => unban.mutate(ban.username)}
                    >
                      <Undo2 className="size-4" />
                      {t('players.unban')}
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
    </div>
  )
}
