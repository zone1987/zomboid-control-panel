import { useTranslation } from 'react-i18next'
import { UserSearch } from 'lucide-react'

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
import { DossierHeader } from './dossier-header'
import { ExperienceCard } from './experience-card'
import { NotesCard } from './notes-card'
import { PlayerHistory } from './player-history'
import { PlayerVitals } from './player-vitals'
import { CharacterCard } from './character-card'
import { ModerationTab } from './moderation-tab'
import { SkillGrid } from './skill-grid'
import { VitalsCard } from './vitals-card'
import type { AccessLevel, Player, TeleportDestination } from './players'

const TABS = ['condition', 'character', 'skills', 'moderation', 'abilities', 'grant', 'notes'] as const

/**
 * One player, beside the list rather than on top of it.
 *
 * Five tabs, one job each. The skills used to be buried at the bottom of
 * the condition tab where nobody found them, and the abilities shared a
 * tab with granting experience — two different acts under one heading.
 */
export function PlayerDossier({
  serverId,
  player,
  players,
  pending,
  onKick,
  onBan,
  onAccessLevel,
  onTeleport,
}: {
  serverId: string
  player: Player | null
  players: Player[]
  pending: boolean
  onKick: () => void
  onBan: () => void
  onAccessLevel: (level: AccessLevel) => void
  onTeleport: (destination: TeleportDestination) => void
}) {
  const { t } = useTranslation()

  if (player === null) {
    return (
      <div className="space-y-3">
        <div className="rounded-md border p-4">
          <SectionMark label={t('players.dossier')} state={t('players.noTarget')} />
        </div>

        <Empty className="rounded-md border border-dashed">
          <EmptyHeader>
            <EmptyMedia variant="icon">
              <UserSearch />
            </EmptyMedia>
            <EmptyTitle>{t('players.noTarget')}</EmptyTitle>
            <EmptyDescription>{t('players.noTargetHint')}</EmptyDescription>
          </EmptyHeader>
        </Empty>
      </div>
    )
  }

  return (
    <div className="space-y-3">
      <DossierHeader player={player} pending={pending} onKick={onKick} onBan={onBan} />

      <Tabs defaultValue="condition">
        <TabsList>
          {TABS.map((tab) => (
            <TabsTrigger key={tab} value={tab}>
              {t(`players.tab.${tab}`)}
            </TabsTrigger>
          ))}
        </TabsList>

        <TabsContent value="condition" className="space-y-3">
          <div className="rounded-md border p-4">
            <PlayerVitals player={player} />
          </div>

          <div className="rounded-md border p-4">
            <SectionMark label={t('players.adjustCondition')} />
            <VitalsCard serverId={serverId} username={player.username} online={player.online} />
          </div>
        </TabsContent>

        <TabsContent value="character">
          <div className="rounded-md border p-4">
            <CharacterCard serverId={serverId} player={player} />
          </div>
        </TabsContent>

        <TabsContent value="skills">
          <div className="rounded-md border p-4">
            <SkillGrid player={player} />
          </div>
        </TabsContent>

        <TabsContent value="moderation">
          <ModerationTab
            serverId={serverId}
            player={player}
            players={players}
            pending={pending}
            onAccessLevel={onAccessLevel}
            onTeleport={onTeleport}
          />
        </TabsContent>

        <TabsContent value="abilities">
          <div className="rounded-md border p-4">
            <AbilityRows
              serverId={serverId}
              username={player.username}
              online={player.online}
            />
          </div>
        </TabsContent>

        <TabsContent value="grant">
          <div className="rounded-md border p-4">
            <SectionMark label={t('players.grantExperience')} />
            <div className="mt-3">
              <ExperienceCard serverId={serverId} player={player} />
            </div>
          </div>
        </TabsContent>

        <TabsContent value="notes" className="space-y-3">
          <div className="rounded-md border p-4">
            <NotesCard serverId={serverId} username={player.username} />
          </div>

          <div className="rounded-md border p-4">
            <SectionMark label={t('players.whatWasDone')} />
            <PlayerHistory serverId={serverId} username={player.username} />
          </div>
        </TabsContent>
      </Tabs>
    </div>
  )
}
