import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Briefcase, Info, Minus, Plus, ThumbsDown, ThumbsUp } from 'lucide-react'

import { cn } from '@/lib/utils'
import { ApiError, errorField } from '@/lib/api'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import { SectionMark } from '@/components/layout/section-mark'
import { CharacterIcon } from './character-icon'
import { skillLabel } from './skills'
import {
  offerableTraits,
  readCharacterSheet,
  setTrait,
  splitTraits,
  type ProfessionDefinition,
  type TraitDefinition,
} from './character'
import type { Player } from './players'

/**
 * The profession and the traits, as the game's own character sheet shows
 * them: artwork, real names, and advantages told apart from drawbacks.
 *
 * The split is the game's, not a judgement made here — a trait's `cost`
 * is positive when it is an advantage you pay for and negative when it
 * refunds points, which is exactly the green and red of the character
 * creator.
 *
 * **The profession is shown but not changeable, deliberately.**
 * `setCharacterProfession` exists, but nothing carries it to the client
 * and the game itself only ever calls it client-side, so a control here
 * would report success while the player saw nothing. Traits *are*
 * changeable, and say plainly that the player's own screen catches up
 * on reconnect.
 */
export function CharacterCard({
  serverId,
  player,
}: {
  serverId: string
  player: Player
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const { data: sheet, isPending } = useQuery({
    queryKey: ['character-sheet'],
    // The game's own tables: they change with a build, not with a request.
    staleTime: 60 * 60 * 1000,
    queryFn: readCharacterSheet,
  })

  const change = useMutation({
    mutationFn: (input: { trait: string; adding: boolean }) =>
      setTrait(serverId, player.username, input.trait, input.adding),
    onSuccess: (result, input) => {
      toast.success(t(input.adding ? 'character.traitAdded' : 'character.traitRemoved'), {
        description: result.reply === '' ? undefined : result.reply,
      })
      void queryClient.invalidateQueries({ queryKey: ['players', serverId] })
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? (errorField(error, 'detail') ?? t('errors.generic'))
          : t('errors.generic'),
      ),
  })

  if (isPending || sheet === undefined) {
    return <Skeleton className="h-64 w-full" />
  }

  const held = player.traits ?? []
  const split = splitTraits(held, sheet.traits)

  const professionId = professionOf(held, sheet.professions)
  const profession = professionId === null ? null : sheet.professions[professionId]

  return (
    <div className="space-y-5">
      <section className="space-y-2">
        <SectionMark label={t('character.professionTitle')} />

        {profession === null || professionId === null ? (
          <p className="text-sm text-muted-foreground">
            {player.online ? t('character.noProfession') : t('players.traitsNeedOnline')}
          </p>
        ) : (
          <ProfessionRow id={professionId} definition={profession} />
        )}
      </section>

      <section className="space-y-3">
        <SectionMark
          label={t('character.traitsTitle')}
          state={String(split.good.length + split.bad.length)}
        />

        {held.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {player.online ? t('character.noTraits') : t('players.traitsNeedOnline')}
          </p>
        ) : (
          <div className="grid gap-x-6 gap-y-4 sm:grid-cols-2">
            <TraitColumn
              tone="good"
              traits={split.good}
              onRemove={(trait) => change.mutate({ trait, adding: false })}
              busy={change.isPending}
            />

            <TraitColumn
              tone="bad"
              traits={split.bad}
              onRemove={(trait) => change.mutate({ trait, adding: false })}
              busy={change.isPending}
            />
          </div>
        )}

        {/* A job's traits are not choices, so they sit apart and carry
            no remove button. */}
        {split.fromProfession.length > 0 && (
          <div className="space-y-1.5 border-t pt-3">
            <p className="font-mono text-[11px] tracking-wide text-muted-foreground uppercase">
              {t('character.fromProfession')}
            </p>

            <div className="flex flex-wrap gap-1.5">
              {split.fromProfession.map(({ id, definition }) => (
                <Badge key={id} variant="secondary" className="gap-1.5 py-1 pl-1">
                  <CharacterIcon icon={definition.icon} label={id} className="size-4" />
                  {t(`character.trait.${id}`, { defaultValue: id })}
                </Badge>
              ))}
            </div>
          </div>
        )}

        {split.unknown.length > 0 && (
          <div className="space-y-1.5 border-t pt-3">
            <p className="font-mono text-[11px] tracking-wide text-muted-foreground uppercase">
              {t('character.unknownTraits')}
            </p>

            <div className="flex flex-wrap gap-1.5">
              {split.unknown.map((id) => (
                <Badge key={id} variant="outline" className="font-mono text-xs">
                  {id}
                </Badge>
              ))}
            </div>
          </div>
        )}
      </section>

      {player.online && (
        <AddTrait
          held={held}
          table={sheet.traits}
          busy={change.isPending}
          onAdd={(trait) => change.mutate({ trait, adding: true })}
        />
      )}

      {/* Said rather than discovered: the change is real server-side and
          invisible on the player's own screen until they reconnect. */}
      <p className="flex items-start gap-2 rounded-md border border-dashed p-3 text-xs text-muted-foreground">
        <Info aria-hidden className="mt-0.5 size-4 shrink-0" />
        {t('character.reconnectNotice')}
      </p>
    </div>
  )
}

/**
 * Which job the character has.
 *
 * Read from the granted trait rather than from the descriptor: the
 * roster does not carry the profession, but a job's own trait is in the
 * trait list, and each one belongs to exactly one job.
 */
function professionOf(
  held: string[],
  professions: Record<string, ProfessionDefinition>,
): string | null {
  for (const [id, definition] of Object.entries(professions)) {
    if (definition.grantedTraits.some((trait) => held.includes(trait))) {
      return id
    }
  }

  return null
}

function ProfessionRow({
  id,
  definition,
}: {
  id: string
  definition: ProfessionDefinition
}) {
  const { t } = useTranslation()

  const boosts = Object.entries(definition.xpBoosts).sort(([, a], [, b]) => b - a)

  return (
    <div className="flex items-start gap-3 rounded-md border p-3">
      <CharacterIcon icon={definition.icon} label={id} className="size-10" />

      <div className="min-w-0 space-y-1.5">
        <p className="flex items-center gap-2 font-medium">
          <Briefcase aria-hidden className="size-4 text-muted-foreground" />
          {t(`character.profession.${id}`, { defaultValue: id })}
        </p>

        {boosts.length > 0 && (
          <div className="flex flex-wrap gap-1">
            {boosts.map(([skill, level]) => (
              <Badge key={skill} variant="outline" className="font-mono text-[11px]">
                {t(`players.skillName.${skillLabel(skill)}`, { defaultValue: skill })} +{level}
              </Badge>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

function TraitColumn({
  tone,
  traits,
  onRemove,
  busy,
}: {
  tone: 'good' | 'bad'
  traits: { id: string; definition: TraitDefinition }[]
  onRemove: (trait: string) => void
  busy: boolean
}) {
  const { t } = useTranslation()

  const Icon = tone === 'good' ? ThumbsUp : ThumbsDown

  return (
    <div className="space-y-2">
      <p className="flex items-center gap-1.5 font-mono text-[11px] tracking-wide uppercase">
        <Icon
          aria-hidden
          className={cn('size-3.5', tone === 'good' ? 'text-emerald-500' : 'text-destructive')}
        />
        <span className="text-muted-foreground">
          {t(tone === 'good' ? 'character.advantages' : 'character.drawbacks')}
        </span>
        <span className="tabular-nums text-muted-foreground opacity-60">{traits.length}</span>
      </p>

      {traits.length === 0 ? (
        <p className="text-xs text-muted-foreground">{t('character.noneOfThese')}</p>
      ) : (
        <div className="space-y-1">
          {traits.map(({ id, definition }) => (
            <div
              key={id}
              className={cn(
                'flex items-center gap-2 rounded-md border px-2 py-1.5',
                // A shape as well as a hue: the border side carries the
                // meaning for anybody who cannot separate the two colours.
                tone === 'good'
                  ? 'border-l-2 border-l-emerald-500/60'
                  : 'border-l-2 border-l-destructive/60',
              )}
            >
              <CharacterIcon icon={definition.icon} label={id} />

              <span className="min-w-0 flex-1 truncate text-sm">
                {t(`character.trait.${id}`, { defaultValue: id })}
              </span>

              <span className="shrink-0 font-mono text-[11px] tabular-nums text-muted-foreground">
                {definition.cost > 0 ? `+${definition.cost}` : definition.cost}
              </span>

              <Button
                size="icon"
                variant="ghost"
                className="size-6 shrink-0"
                disabled={busy}
                aria-label={t('character.removeTrait', {
                  trait: t(`character.trait.${id}`, { defaultValue: id }),
                })}
                onClick={() => onRemove(id)}
              >
                <Minus className="size-3.5" />
              </Button>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

/** What is left to choose, searchable because there are ninety-seven. */
function AddTrait({
  held,
  table,
  busy,
  onAdd,
}: {
  held: string[]
  table: Record<string, TraitDefinition>
  busy: boolean
  onAdd: (trait: string) => void
}) {
  const { t } = useTranslation()
  const [term, setTerm] = useState('')

  const offered = offerableTraits(held, table)
  const needle = term.trim().toLowerCase()

  const shown = offered.filter(({ id }) => {
    if (needle === '') {
      return true
    }

    const name = t(`character.trait.${id}`, { defaultValue: id }).toLowerCase()

    return name.includes(needle) || id.toLowerCase().includes(needle)
  })

  return (
    <Collapsible className="border-t pt-3">
      <CollapsibleTrigger className="w-full text-left font-mono text-[11px] tracking-wide text-muted-foreground uppercase hover:text-foreground">
        › {t('character.addTrait', { count: offered.length })}
      </CollapsibleTrigger>

      <CollapsibleContent className="space-y-2 pt-2">
        <Input
          value={term}
          placeholder={t('character.searchTraits')}
          aria-label={t('character.searchTraits')}
          onChange={(event) => setTerm(event.target.value)}
        />

        <div className="max-h-64 space-y-1 overflow-y-auto">
          {shown.map(({ id, definition }) => (
            <button
              key={id}
              type="button"
              disabled={busy}
              className="flex w-full items-center gap-2 rounded-md border px-2 py-1.5 text-left transition-colors hover:bg-muted/50 disabled:opacity-50"
              onClick={() => onAdd(id)}
            >
              <CharacterIcon icon={definition.icon} label={id} />

              <span className="min-w-0 flex-1 truncate text-sm">
                {t(`character.trait.${id}`, { defaultValue: id })}
              </span>

              <span
                className={cn(
                  'shrink-0 font-mono text-[11px] tabular-nums',
                  definition.cost > 0 ? 'text-emerald-500' : 'text-destructive',
                )}
              >
                {definition.cost > 0 ? `+${definition.cost}` : definition.cost}
              </span>

              <Plus aria-hidden className="size-3.5 shrink-0 text-muted-foreground" />
            </button>
          ))}

          {shown.length === 0 && (
            <p className="px-2 py-4 text-center text-sm text-muted-foreground">
              {t('character.noTraitMatch')}
            </p>
          )}
        </div>
      </CollapsibleContent>
    </Collapsible>
  )
}
