import { useTranslation } from 'react-i18next'

import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { skillLabel } from './skills'
import type { TraitDefinition } from './character'

/**
 * What a trait actually does, on hover.
 *
 * The text is the game's own `UIDescription`, and 84 of the 97 traits
 * have one. The other thirteen are pure XP boosts — "Brawler" carries
 * `Axe=1;Blunt=1` and nothing else — so the boosts are shown as well
 * and stand in as the description where there is no prose.
 *
 * The game writes `<br>` inside twenty of those strings. Those are
 * newlines in the locale rather than markup, so nothing reaches the DOM
 * as HTML.
 */
export function TraitTooltip({
  id,
  definition,
  children,
}: {
  id: string
  definition: TraitDefinition
  children: React.ReactNode
}) {
  const { t } = useTranslation()

  const description = t(`character.traitDescription.${id}`, { defaultValue: '' })
  const boosts = Object.entries(definition.xpBoosts).sort(([, a], [, b]) => b - a)

  // Nothing to say: no tooltip rather than an empty box.
  if (description === '' && boosts.length === 0) {
    return <>{children}</>
  }

  return (
    <Tooltip>
      <TooltipTrigger asChild>{children}</TooltipTrigger>

      <TooltipContent side="top" className="max-w-72 space-y-1.5">
        {description !== '' && <p className="whitespace-pre-line">{description}</p>}

        {boosts.length > 0 && (
          <p className="font-mono text-[11px] opacity-80">
            {boosts
              .map(
                ([perk, level]) =>
                  `${t(`players.skillName.${skillLabel(perk)}`, { defaultValue: perk })} ${
                    level > 0 ? '+' : ''
                  }${level}`,
              )
              .join(' · ')}
          </p>
        )}

        {/* Why some traits vanish from the offer list once this one is
            held — the page says it in general, this says which. */}
        {definition.exclusive.length > 0 && (
          <p className="text-[11px] opacity-70">
            {t('character.clashesWith', {
              traits: definition.exclusive
                .slice(0, 4)
                .map((other) => t(`character.trait.${other}`, { defaultValue: other }))
                .join(', '),
            })}
          </p>
        )}
      </TooltipContent>
    </Tooltip>
  )
}
