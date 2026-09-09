import { useTranslation } from 'react-i18next'
import { Check, CornerDownRight, HelpCircle, RotateCcw } from 'lucide-react'

import { cn } from '@/lib/utils'
import type { DependencyNode } from './mods'

/**
 * The requirement chain, drawn as a tree.
 *
 * A mod's requirements have requirements of their own, and those are
 * just as missing — so the whole chain is shown rather than the one
 * level Steam names directly.
 */
export function DependencyTree({
  nodes,
  installedIds,
  onOpen,
}: {
  nodes: DependencyNode[]
  installedIds: Set<string>
  onOpen: (workshopId: string) => void
}) {
  // The root is the mod being looked at; its children are what it needs.
  const children = nodes[0]?.children ?? []

  if (children.length === 0) {
    return null
  }

  return (
    <ul className="space-y-1">
      {children.map((node) => (
        <TreeNode
          key={node.workshopId}
          node={node}
          depth={0}
          installedIds={installedIds}
          onOpen={onOpen}
        />
      ))}
    </ul>
  )
}

function TreeNode({
  node,
  depth,
  installedIds,
  onOpen,
}: {
  node: DependencyNode
  depth: number
  installedIds: Set<string>
  onOpen: (workshopId: string) => void
}) {
  const { t } = useTranslation()
  const installed = installedIds.has(node.workshopId)

  return (
    <li>
      <div
        className="flex items-center gap-1.5"
        // Indented by depth rather than nested lists: a chain four deep
        // in a 20rem column runs out of width otherwise.
        style={{ paddingLeft: `${depth * 0.85}rem` }}
      >
        {depth > 0 && (
          <CornerDownRight className="size-3 shrink-0 text-muted-foreground" aria-hidden />
        )}

        <button
          type="button"
          onClick={() => onOpen(node.workshopId)}
          className="min-w-0 flex-1 truncate rounded-sm px-1 py-1 text-left text-sm hover:bg-accent/50 sm:py-0.5"
        >
          {node.title ?? node.workshopId}
        </button>

        {/* Three states, and the third is why this is not a checkmark
            and its absence: a mod nobody could describe is not known to
            be missing (rule 6c). */}
        {node.repeats ? (
          <RotateCcw
            className="size-3.5 shrink-0 text-muted-foreground"
            aria-label={t('mods.repeats')}
          />
        ) : !node.resolved ? (
          <HelpCircle
            className="size-3.5 shrink-0 text-muted-foreground"
            aria-label={t('mods.unresolvedDependency')}
          />
        ) : installed ? (
          <Check className="size-3.5 shrink-0 text-primary" aria-label={t('mods.installed')} />
        ) : (
          <span
            className={cn('size-2 shrink-0 rounded-full bg-amber-500')}
            aria-label={t('mods.notInstalledYet')}
          />
        )}
      </div>

      {node.children.length > 0 && (
        <ul className="space-y-1">
          {node.children.map((child) => (
            <TreeNode
              key={child.workshopId}
              node={child}
              depth={depth + 1}
              installedIds={installedIds}
              onOpen={onOpen}
            />
          ))}
        </ul>
      )}
    </li>
  )
}
