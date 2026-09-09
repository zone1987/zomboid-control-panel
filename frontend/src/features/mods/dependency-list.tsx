import { useTranslation } from 'react-i18next'
import { Check, HelpCircle } from 'lucide-react'

import { requirementsOf, type DependencyNode } from './mods'

/**
 * What this mod needs, each named once.
 *
 * Drawn as a tree it answered the wrong question: two mods requiring
 * each other became six rows repeating two names, and an operator
 * counting what to install counted four. The chain is walked to its
 * end and flattened, so the list is the answer.
 */
export function DependencyList({
  nodes,
  installedIds,
  onOpen,
}: {
  nodes: DependencyNode[]
  installedIds: Set<string>
  onOpen: (workshopId: string) => void
}) {
  const { t } = useTranslation()
  const requirements = requirementsOf(nodes)

  if (requirements.length === 0) {
    return null
  }

  const mutual = requirements.some((requirement) => requirement.mutual)
  const missing = requirements.filter(
    (requirement) => requirement.resolved && !installedIds.has(requirement.workshopId),
  ).length

  return (
    <div className="space-y-2">
      <ul className="space-y-1">
        {requirements.map((requirement) => {
          const installed = installedIds.has(requirement.workshopId)

          return (
            <li key={requirement.workshopId} className="flex items-center gap-2">
              {/* Three states, and the third is why this is not a tick
                  and its absence: a mod nobody could describe is not
                  known to be missing (rule 6c). */}
              {!requirement.resolved ? (
                <HelpCircle
                  className="size-3.5 shrink-0 text-muted-foreground"
                  aria-label={t('mods.unresolvedDependency')}
                />
              ) : installed ? (
                <Check className="size-3.5 shrink-0 text-primary" aria-label={t('mods.installed')} />
              ) : (
                <span
                  className="size-2 shrink-0 rounded-full bg-amber-500"
                  aria-label={t('mods.notInstalledYet')}
                />
              )}

              {/* The name gets the width, not the label: the icon
                  already carries the state, and a truncated mod name is
                  the one thing on this row nobody can look up. */}
              <button
                type="button"
                onClick={() => onOpen(requirement.workshopId)}
                className="min-w-0 flex-1 truncate rounded-sm px-1 py-1 text-left text-sm hover:bg-accent/50 sm:py-0.5"
                title={requirement.title ?? requirement.workshopId}
              >
                {requirement.title ?? requirement.workshopId}
              </button>
            </li>
          )
        })}
      </ul>

      {/* The row has no room for a word beside the name, so the count
          says the state once for all of them -- colour alone must never
          carry it (rule 9). */}
      {missing > 0 && (
        <p className="text-xs text-muted-foreground">
          {t('mods.missingCount', { count: missing })}
        </p>
      )}

      {/* A circle is a fact about the pair, not another level to draw. */}
      {mutual && (
        <p className="text-xs text-muted-foreground">{t('mods.mutualRequirement')}</p>
      )}
    </div>
  )
}
