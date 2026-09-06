import { useTranslation } from 'react-i18next'
import { UserSearch } from 'lucide-react'

import { Copyable } from '@/components/ui/copyable'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Empty,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from '@/components/ui/empty'
import { SectionMark } from '@/components/layout/section-mark'
import { AbilityRows } from './ability-rows'
import { ExperienceCard } from './experience-card'
import { PlayerVitals } from './player-vitals'
import type { Player } from './players'

/**
 * One player, beside the list rather than on top of it.
 *
 * `PlayerDetail` was a dialog, so inspecting somebody covered the list
 * and moving to the next one meant close, find, open. Comparing two
 * players is the job often enough that the column is the right shape —
 * and the tabs stay visible with nothing selected, so the page says what
 * it can do before it is asked.
 *
 * Kick and ban stay on the row in the list rather than being repeated
 * here: they are one click away already, and two places to ban somebody
 * from is one place too many to keep in step.
 */
export function PlayerDossier({
  serverId,
  player,
}: {
  serverId: string
  player: Player | null
}) {
  const { t } = useTranslation()

  return (
    <div className="space-y-3">
      {player === null ? (
        <Header>
          <SectionMark label={t('players.dossier')} state={t('players.noTarget')} />
        </Header>
      ) : (
        <Header>
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <span
                aria-hidden
                className={
                  player.online
                    ? 'size-2 shrink-0 rounded-full bg-emerald-500'
                    : 'size-2 shrink-0 rounded-full bg-muted-foreground/40'
                }
              />
              <h2 className="truncate text-lg font-semibold">{player.username}</h2>
            </div>

            <p className="text-xs text-muted-foreground">
              {player.online ? t('players.online') : t('players.offline')}
            </p>

            {/* A ban needs the id, and an id is pasted rather than read. */}
            {player.steamId !== null && player.steamId !== '' && (
              <Copyable value={player.steamId} className="mt-1" />
            )}
          </div>
        </Header>
      )}

      {/* The tabs stay even with nothing chosen, so the page shows what it
          is for rather than an empty panel. */}
      <Tabs defaultValue="vitals">
        <TabsList>
          <TabsTrigger value="vitals">{t('players.vitalsTab')}</TabsTrigger>
          <TabsTrigger value="abilities">{t('players.abilitiesTab')}</TabsTrigger>
          <TabsTrigger value="spawn">{t('players.spawnTab')}</TabsTrigger>
        </TabsList>

        <TabsContent value="vitals">
          {player === null ? (
            <NoTarget />
          ) : (
            <div className="rounded-md border p-4">
              <PlayerVitals player={player} />
            </div>
          )}
        </TabsContent>

        <TabsContent value="abilities">
          {player === null ? (
            <NoTarget />
          ) : (
            <div className="rounded-md border p-4">
              <AbilityRows
                serverId={serverId}
                username={player.username}
                online={player.online}
              />
            </div>
          )}
        </TabsContent>

        <TabsContent value="spawn">
          {player === null ? (
            <NoTarget />
          ) : (
            <div className="rounded-md border p-4">
              <ExperienceCard serverId={serverId} player={player} />
            </div>
          )}
        </TabsContent>
      </Tabs>
    </div>
  )
}

function Header({ children }: { children: React.ReactNode }) {
  return <div className="rounded-md border p-4">{children}</div>
}

/** Named rather than blank: the reason is a missing choice, not a fault. */
function NoTarget() {
  const { t } = useTranslation()

  return (
    <Empty className="rounded-md border border-dashed">
      <EmptyHeader>
        <EmptyMedia variant="icon">
          <UserSearch />
        </EmptyMedia>
        <EmptyTitle>{t('players.noTarget')}</EmptyTitle>
        <EmptyDescription>{t('players.noTargetHint')}</EmptyDescription>
      </EmptyHeader>
    </Empty>
  )
}
