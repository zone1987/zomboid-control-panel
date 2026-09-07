import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { BookOpen, ChevronsRight, GraduationCap } from 'lucide-react'

import { cn } from '@/lib/utils'
import { ApiError, errorField } from '@/lib/api'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { SectionMark } from '@/components/layout/section-mark'
import { groupSkills, MAX_SKILL_LEVEL, skillLabel, unknownSkills } from './skills'
import {
  addSkillXp,
  levelProgress,
  MAX_SKILL_XP,
  readSkillDetail,
  setSkillLevel,
  type Player,
  type SkillDetail,
} from './players'

/**
 * Every skill this character has, grouped the way the game groups them,
 * with the level set by clicking the pip you want.
 *
 * Shown in full rather than only the trained ones: "level 0 across the
 * board" is an answer a dossier has to be able to give, and a list that
 * omits them cannot tell "untrained" from "not reported".
 *
 * The pips are the control as well as the display. Ten discrete marks
 * are what a skill level *is*, so clicking the seventh to mean seven
 * needs no slider, no field and no arithmetic — and clicking the pip
 * that is already the level sets it to zero, which is the only sensible
 * meaning left for that click.
 */
export function SkillGrid({
  serverId,
  player,
}: {
  serverId: string
  player: Player
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['players', serverId] })

  const report = (successKey: string) => ({
    onSuccess: async (result: { reply: string }) => {
      await refresh()
      toast.success(t(successKey), {
        description: result.reply === '' ? undefined : result.reply,
      })
    },
    onError: (error: unknown) =>
      toast.error(
        error instanceof ApiError
          ? (errorField(error, 'detail') ?? t('errors.generic'))
          : t('errors.generic'),
      ),
  })

  const level = useMutation({
    mutationFn: (input: { skill: string; level: number }) =>
      setSkillLevel(serverId, player.username, input.skill, input.level),
    ...report('players.skillSet'),
  })

  // The thresholds, the boost and the book multiplier: none of them is
  // in the roster, and all three need the character loaded.
  const { data: detail } = useQuery({
    queryKey: ['skill-detail', serverId, player.username],
    queryFn: () => readSkillDetail(serverId, player.username),
    enabled: player.online,
    retry: false,
  })

  const groups = groupSkills(player.skills)
  const extra = unknownSkills(player.skills)
  const trained = groups.reduce((sum, group) => sum + group.trained, 0)

  const boosted = Object.entries(detail?.skills ?? {}).filter(
    ([, entry]) => entry.multiplier > 0,
  )

  if (player.skills === null) {
    return (
      <p className="text-sm text-muted-foreground">
        {player.online ? t('players.noSkillsReported') : t('players.skillsNeedOnline')}
      </p>
    )
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <p className="text-sm text-muted-foreground">
          {trained === 0 ? t('players.nothingTrained') : t('players.trainedCount', { count: trained })}
        </p>

        {/* Never offer what cannot work: the level goes through the
            bridge, which needs the character loaded. */}
        {!player.online && (
          <Badge variant="secondary" className="text-xs">
            {t('players.skillsReadOnlyOffline')}
          </Badge>
        )}
      </div>

      <div className="grid gap-x-8 gap-y-5 sm:grid-cols-2">
        {groups.map((group) => (
          <section key={group.id} className="space-y-2">
            <h3 className="flex items-baseline gap-2 font-mono text-[11px] tracking-wide text-muted-foreground uppercase">
              {t(`players.skillGroup.${group.id}`, { defaultValue: group.id })}

              {group.trained > 0 && <span className="tabular-nums opacity-60">{group.trained}</span>}
            </h3>

            <div className="space-y-1">
              {group.skills.map((skill) => (
                <SkillRow
                  key={skill.id}
                  label={skill.label}
                  level={skill.level}
                  detail={detail?.skills[skill.id]}
                  editable={player.online}
                  busy={level.isPending}
                  onSet={(wanted) => level.mutate({ skill: skill.id, level: wanted })}
                />
              ))}
            </div>
          </section>
        ))}
      </div>

      {/* The read books still in effect. The game shows these as three
          animating arrows beside a skill name and nowhere else, so an
          operator has no way to know a multiplier is running. */}
      {boosted.length > 0 && (
        <section className="space-y-2 rounded-md border border-dashed p-3">
          <p className="flex items-center gap-1.5 font-mono text-[11px] tracking-wide text-muted-foreground uppercase">
            <BookOpen aria-hidden className="size-3.5" />
            {t('players.activeMultipliers')}
          </p>

          <div className="flex flex-wrap gap-1.5">
            {boosted.map(([id, entry]) => (
              <Badge key={id} variant="outline" className="gap-1.5">
                {t(`players.skillName.${skillLabel(id)}`, { defaultValue: id })}
                <span className="font-mono text-xs text-primary">
                  ×{entry.multiplier.toFixed(entry.multiplier % 1 === 0 ? 0 : 1)}
                </span>
              </Badge>
            ))}
          </div>

          <p className="text-xs text-muted-foreground">{t('players.multiplierHint')}</p>
        </section>
      )}

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

      {player.online && <ExperienceRow serverId={serverId} player={player} onDone={refresh} />}
    </div>
  )
}

/**
 * One skill: its name, ten clickable pips, its level.
 *
 * Hovering shows what a click would do, because a row of ten identical
 * marks gives no clue on its own that it is a control.
 */
function SkillRow({
  label,
  level,
  detail,
  editable,
  busy,
  onSet,
}: {
  label: string
  level: number
  detail: SkillDetail | undefined
  editable: boolean
  busy: boolean
  onSet: (level: number) => void
}) {
  const { t, i18n } = useTranslation()
  const [hovered, setHovered] = useState<number | null>(null)

  const name = t(`players.skillName.${label}`, { defaultValue: label })
  const shown = hovered ?? level

  const progress = detail === undefined ? null : levelProgress(detail)
  const number = (value: number) => Math.round(value).toLocaleString(i18n.language)

  // What hovering the current level says: the game shows this nowhere,
  // and "how much more" is the question a level number cannot answer.
  const remainder =
    progress === null
      ? null
      : t('players.xpToNextLevel', {
          done: number(progress.done),
          span: number(progress.done + progress.needed),
          left: number(progress.needed),
          level: level + 1,
        })

  const boosted = (detail?.boost ?? 0) >= 3
  const hasBook = (detail?.multiplier ?? 0) > 0

  return (
    <div className="flex items-center gap-2">
      <span
        title={
          detail === undefined
            ? undefined
            : boostTitle(
                t('players.skillBoost', { boost: detail.boost }),
                detail.multiplier > 0
                  ? t('players.bookActive', { factor: detail.multiplier })
                  : null,
              )
        }
        className={cn(
          'flex w-28 shrink-0 items-center gap-1 truncate text-sm',
          level === 0 && !boosted && 'text-muted-foreground',
          // The game golds a skill its profession boosts by three.
          boosted && 'text-amber-400',
        )}
      >
        <span className="truncate">{name}</span>

        {/* The game's own three arrows, still rather than animating. */}
        {hasBook && (
          <ChevronsRight
            aria-label={t('players.bookActive', { factor: detail?.multiplier ?? 0 })}
            className="size-3 shrink-0 text-primary"
          />
        )}
      </span>

      <div
        className="flex flex-1 gap-0.5"
        role={editable ? 'group' : 'img'}
        aria-label={editable ? undefined : t('players.skillAtLevel', { skill: name, level })}
        onMouseLeave={() => setHovered(null)}
      >
        {Array.from({ length: MAX_SKILL_LEVEL }, (_, index) => {
          const pip = index + 1
          const wanted = pip === level ? 0 : pip

          // Hovering the level you already have asks what is left of it;
          // hovering any other says what a click would set.
          const hint =
            pip === level && remainder !== null
              ? remainder
              : t('players.setSkillTo', { skill: name, level: wanted })

          if (!editable) {
            return (
              <span
                key={pip}
                title={pip === level ? (remainder ?? undefined) : undefined}
                className={cn(
                  'h-1.5 flex-1 rounded-full',
                  pip <= level ? 'bg-primary' : 'bg-muted',
                )}
              />
            )
          }

          return (
            <button
              key={pip}
              type="button"
              disabled={busy}
              title={hint}
              aria-label={hint}
              className="group flex h-4 flex-1 items-center px-px disabled:cursor-not-allowed"
              onMouseEnter={() => setHovered(pip)}
              onFocus={() => setHovered(pip)}
              onBlur={() => setHovered(null)}
              onClick={() => onSet(wanted)}
            >
              <span
                className={cn(
                  'h-1.5 w-full rounded-full transition-colors',
                  pip <= shown
                    ? hovered !== null
                      ? pip <= (hovered === level ? 0 : hovered)
                        ? 'bg-primary'
                        : 'bg-primary/30'
                      : 'bg-primary'
                    : 'bg-muted group-hover:bg-primary/40',
                )}
              />
            </button>
          )
        })}
      </div>

      {/* The partial level, so a skill at 6 with almost 7 does not read
          the same as one that just reached 6. */}
      {progress !== null && progress.fraction > 0 && (
        <span
          title={remainder ?? undefined}
          className="w-7 shrink-0 text-right font-mono text-[10px] tabular-nums text-muted-foreground"
        >
          {Math.round(progress.fraction * 100)}%
        </span>
      )}

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

/** Why a skill name is gold: the profession and traits behind it. */
function boostTitle(boost: string, book: string | null): string {
  return book === null ? boost : `${boost} · ${book}`
}

/**
 * Raw experience in one skill, beneath the grid the user asked for.
 *
 * Kept beside the levels rather than in its own tab: setting a level and
 * nudging one are the same job, and the reply says which level the XP
 * landed on — the one thing a level click cannot tell you.
 */
function ExperienceRow({
  serverId,
  player,
  onDone,
}: {
  serverId: string
  player: Player
  onDone: () => void
}) {
  const { t } = useTranslation()

  const [skill, setSkill] = useState<string | null>(null)
  const [amount, setAmount] = useState(500)
  const [multiplied, setMultiplied] = useState(false)

  const groups = groupSkills(player.skills)

  const grant = useMutation({
    mutationFn: () => addSkillXp(serverId, player.username, skill as string, amount, multiplied),
    onSuccess: (result) => {
      toast.success(
        result.level !== undefined && result.level !== result.levelBefore
          ? t('players.xpReachedLevel', { level: result.level })
          : t('players.xpGranted', { amount }),
        { description: result.reply === '' ? undefined : result.reply },
      )
      onDone()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? (errorField(error, 'detail') ?? t('errors.generic'))
          : t('errors.generic'),
      ),
  })

  const ready = skill !== null && amount >= 1 && amount <= MAX_SKILL_XP

  return (
    <section className="space-y-3 border-t pt-4">
      <SectionMark label={t('players.grantExperience')} />

      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-48 flex-1 space-y-1.5">
          <Label htmlFor="xp-skill">{t('players.skill')}</Label>

          <Select value={skill ?? undefined} onValueChange={setSkill}>
            <SelectTrigger id="xp-skill" className="w-full">
              <SelectValue placeholder={t('players.chooseSkill')} />
            </SelectTrigger>

            <SelectContent>
              {groups.map((group) => (
                <SelectGroup key={group.id}>
                  <SelectLabel>
                    {t(`players.skillGroup.${group.id}`, { defaultValue: group.id })}
                  </SelectLabel>

                  {group.skills.map((entry) => (
                    <SelectItem key={entry.id} value={entry.id}>
                      {t(`players.skillName.${entry.label}`, { defaultValue: entry.label })}
                      <span className="ml-1.5 font-mono text-xs text-muted-foreground">
                        {entry.level}
                      </span>
                    </SelectItem>
                  ))}
                </SelectGroup>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="xp-amount">{t('players.experience')}</Label>

          <div className="flex items-center gap-2">
            <Input
              id="xp-amount"
              inputMode="numeric"
              className="w-24 text-center font-mono tabular-nums"
              value={String(amount)}
              onChange={(event) => {
                const typed = Number.parseInt(event.target.value, 10)

                setAmount(Number.isNaN(typed) ? 1 : Math.min(MAX_SKILL_XP, Math.max(1, typed)))
              }}
            />

            {/* Type nothing you could click. */}
            {[100, 500, 2000, 10000].map((step) => (
              <Button
                key={step}
                size="sm"
                variant={amount === step ? 'secondary' : 'ghost'}
                className="px-2 font-mono text-xs"
                onClick={() => setAmount(step)}
              >
                {step}
              </Button>
            ))}
          </div>
        </div>

        <Button disabled={!ready || grant.isPending} onClick={() => grant.mutate()}>
          <GraduationCap className="size-4" />
          {grant.isPending ? t('common.loading') : t('players.grantExperience')}
        </Button>
      </div>

      <div className="flex items-center gap-2">
        <Switch id="xp-multiplier" checked={multiplied} onCheckedChange={setMultiplied} />
        <Label htmlFor="xp-multiplier" className="text-sm font-normal">
          {t('players.useServerMultiplier')}
        </Label>
      </div>
    </section>
  )
}
