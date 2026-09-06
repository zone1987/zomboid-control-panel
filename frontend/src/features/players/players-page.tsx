import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'

import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { getServer } from '@/features/servers/servers'
import { PlayerTable } from './player-table'
import { BanList } from './ban-list'
import { ModerationHistory } from './moderation-history'

export function PlayersPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()

  const { data: server, isPending } = useQuery({
    queryKey: ['server', id],
    queryFn: () => getServer(id),
  })

  if (isPending) {
    return <Skeleton className="h-96 w-full" />
  }

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('players.title')}</h1>
        <p className="text-muted-foreground">
          {server ? t('players.descriptionFor', { server: server.name }) : t('players.description')}
        </p>
      </div>

      <Tabs defaultValue="players">
        <TabsList>
          <TabsTrigger value="players">{t('players.playersTab')}</TabsTrigger>
          <TabsTrigger value="bans">{t('players.bansTab')}</TabsTrigger>
          <TabsTrigger value="history">{t('players.historyTab')}</TabsTrigger>
        </TabsList>

        <TabsContent value="players" className="space-y-4">
          <PlayerTable serverId={id} />
        </TabsContent>

        <TabsContent value="bans">
          <BanList serverId={id} />
        </TabsContent>

        <TabsContent value="history">
          <ModerationHistory serverId={id} />
        </TabsContent>
      </Tabs>
    </div>
  )
}
