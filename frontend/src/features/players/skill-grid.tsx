import { useTranslation } from 'react-i18next'

import { cn } from '@/lib/utils'
import { Badge } from '@/components/ui/badge'
import { groupSkills, MAX_SKILL_LEVEL, skillLabel, unknownSkills } from './skills'
import type { Player } from './players'

/**
 * Every skill this character has, grouped the way the game groups them.
 *
 * Shown in full rather than only the trained ones: "level 0 across the
 * board" is an answer a dossier has to be able to give, and a list that
 * silently omits them cannot distinguish "untrained" from "not reported".
 *
 * The pips are ten discrete marks rather than a bar, because a skill
 * level *is* discrete — 7 out of 10 reads off pips at a glance and needs
 * arithmetic off a bar.
 */
export function SkillGrid({ player }: { player: Player }) {
  const { t } = useTranslation()

  const groups = groupSkills(player.skills)
  const extra = unknownSkills(player.skills)
  const trained = groups.reduce((sum, group) => sum + group.trained, 0)

  if (player.skills === null) {
    return (
      <p className="text-sm text-muted-foreground">
        {player.online ? t('players.noSkillsReported') : t('players.skillsNeedOnline')}
      </p>
    )
  }

  return (
    <div className="space-y-5">
      <p className="text-sm text-muted-foreground">
        {trained === 0
          ? t('players.nothingTrained')
          : t('players.trainedCount', { count: trained })}
      </p>

      {/* Two columns where there is room: a skill row is a label and ten
          pips, not something that fills a page width. */}
      <div className="grid gap-x-8 gap-y-5 sm:grid-cols-2">
        {groups.map((group) => (
          <section key={group.id} className="space-y-2">
            <h3 className="flex items-baseline gap-2 font-mono text-[11px] tracking-wide text-muted-foreground uppercase">
              {t(`players.skillGroup.${group.id}`, { defaultValue: group.id })}

              {group.trained > 0 && (
                <span className="tabular-nums opacity-60">{group.trained}</span>
              )}
            </h3>

            <div className="space-y-1">
              {group.skills.map((skill) => (
                <SkillRow key={skill.id} label={skill.label} level={skill.level} />
              ))}
            </div>
          </section>
        ))}
      </div>

      {/* A mod's skill, or one this table predates. Shown rather than
          dropped: the server reported it, so it exists. */}
      {extra.length > 0 && (
        <section className="space-y-2 border-t pt-3">
          <h3 className="font-mono text-[11px] tracking-wide text-muted-foreground uppercase">
            {t('players.otherSkills')}
          </h3>

          <div className="flex flex-wrap gap-1.5">
            {extra.map((id) => (
              <Badge key={id} variant="secondary" className="font-mono text-xs">
                {skillLabel(id)} {player.skills?.[id] ?? 0}
              </Badge>
            ))}
          </div>
        </section>
      )}
    </div>
  )
}

function SkillRow({ label, level }: { label: string; level: number }) {
  const { t } = useTranslation()

  return (
    <div className="flex items-center gap-2">
      <span
        className={cn(
          'w-28 shrink-0 truncate text-sm',
          level === 0 && 'text-muted-foreground',
        )}
      >
        {t(`players.skillName.${label}`, { defaultValue: label })}
      </span>

      <div
        className="flex flex-1 gap-0.5"
        role="img"
        aria-label={t('players.skillAtLevel', { skill: label, level })}
      >
        {Array.from({ length: MAX_SKILL_LEVEL }, (_, index) => (
          <span
            key={index}
            className={cn(
              'h-1.5 flex-1 rounded-full',
              index < level ? 'bg-primary' : 'bg-muted',
            )}
          />
        ))}
      </div>

      <span
        className={cn(
          'w-4 shrink-0 text-right font-mono text-xs tabular-nums',
          level === 0 ? 'text-muted-foreground/50' : 'font-medium',
        )}
      >
        {level}
      </span>
    </div>
  )
}
