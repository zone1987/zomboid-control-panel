import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'

import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { getServer } from '@/features/servers/servers'
import { PlayerTable } from './player-table'
import { PlayerDossier } from './player-dossier'
import { BanList } from './ban-list'
import { ModerationHistory } from './moderation-history'
import type { Player } from './players'

/**
 * The list and one player's dossier, side by side.
 *
 * The dossier used to be a dialog, so inspecting somebody covered the
 * list and moving on meant close, find, open. Comparing two players is
 * common enough that the column is the right shape — and on a narrow
 * screen the two simply stack, dossier first once a player is chosen.
 */
export function PlayersPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const [chosen, setChosen] = useState<Player | null>(null)

  const { data: server, isPending } = useQuery({
    queryKey: ['server', id],
    queryFn: () => getServer(id),
  })

  if (isPending) {
    return <Skeleton className="h-96 w-full" />
  }

  return (
    <div className="space-y-6">
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
          {/* The list keeps the room it needs for five columns; the
              dossier takes a fixed share beside it rather than half. */}
          <div className="grid gap-4 xl:grid-cols-[1fr_26rem]">
            <PlayerTable
              serverId={id}
              selected={chosen?.username ?? null}
              onSelect={setChosen}
            />

            <PlayerDossier serverId={id} player={chosen} />
          </div>
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
