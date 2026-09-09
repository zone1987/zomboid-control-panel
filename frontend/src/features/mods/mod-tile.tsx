import { useTranslation } from 'react-i18next'
import { Check, Link2, Loader2, Map, Plus, Trash2, TriangleAlert } from 'lucide-react'

import { cn } from '@/lib/utils'
import { formatDate } from '@/lib/dates'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { ModCover } from './mod-cover'
import { displayName, formatSize, type Mod } from './mods'

/**
 * One mod in the grid.
 *
 * The cover carries the recognition — 241 vehicles taught the same
 * lesson — so it leads, and the name, author-facing tags and the two
 * facts that decide whether to install sit beside it.
 */
export function ModTile({
  mod,
  installed,
  pending,
  onOpen,
  onToggle,
}: {
  mod: Mod
  installed: boolean
  pending: boolean
  onOpen: () => void
  onToggle: () => void
}) {
  const { t, i18n } = useTranslation()
  const size = formatSize(mod.fileSize)
  const updated = mod.updatedAt === null ? null : formatDate(mod.updatedAt, i18n.language)

  return (
    <div
      className={cn(
        // The whole tile is the target, so the border says so rather
        // than an underline, which reads as a link inside a card that
        // is itself a link.
        'pz-mod-tile flex gap-3 rounded-md border p-3',
        installed ? 'border-primary/40 bg-primary/5' : 'hover:bg-accent/40',
      )}
    >
      <button
        type="button"
        onClick={onOpen}
        className="shrink-0 rounded-sm focus-visible:outline-none"
        aria-label={t('mods.openDetail', { name: displayName(mod) })}
      >
        <ModCover mod={mod} className="size-16" />
      </button>

      {/* min-w-0 so a long title truncates inside the card instead of
          widening the grid column (rule 10g1). */}
      <div className="flex min-w-0 flex-1 flex-col gap-1.5">
        <div className="flex items-start gap-2">
          <button
            type="button"
            onClick={onOpen}
            className="min-w-0 flex-1 text-left text-sm font-medium"
          >
            <span className="line-clamp-2">{displayName(mod)}</span>
          </button>

          <Button
            type="button"
            variant={installed ? 'ghost' : 'outline'}
            size="icon"
            className="size-8 shrink-0"
            disabled={pending}
            onClick={onToggle}
            aria-label={installed ? t('mods.remove') : t('mods.add')}
            title={installed ? t('mods.remove') : t('mods.add')}
          >
            {pending ? (
              <Loader2 className="size-4 animate-spin" />
            ) : installed ? (
              <Trash2 className="size-4" />
            ) : (
              <Plus className="size-4" />
            )}
          </Button>
        </div>

        <div className="flex flex-wrap items-center gap-1">
          {installed && (
            <Badge variant="success" className="gap-1 text-xs">
              <Check className="size-3" />
              {t('mods.installed')}
            </Badge>
          )}

          {/* A shape as well as a colour, because roughly one man in
              twelve cannot separate red from green. */}
          {mod.buildVerdict === 'mismatch' && (
            <Badge variant="warning" className="gap-1 text-xs">
              <TriangleAlert className="size-3" />
              {t('mods.buildMismatch', { builds: mod.declaredBuilds.join(', ') })}
            </Badge>
          )}

          {mod.isMap && (
            <Badge variant="outline" className="gap-1 text-xs">
              <Map className="size-3" />
              {t('mods.isMap')}
            </Badge>
          )}

          {mod.dependencies.length > 0 && (
            <Badge variant="outline" className="gap-1 text-xs">
              <Link2 className="size-3" />
              {t('mods.needsCount', { count: mod.dependencies.length })}
            </Badge>
          )}

          {mod.tags
            .filter((tag) => !/^Build \d+$/.test(tag))
            .slice(0, 3)
            .map((tag) => (
              <Badge key={tag} variant="secondary" className="text-xs font-normal">
                {tag}
              </Badge>
            ))}
        </div>

        <p className="text-xs text-muted-foreground">
          {updated !== null && t('mods.updatedOn', { date: updated })}
          {updated !== null && size !== null && ' · '}
          {size}
        </p>
      </div>
    </div>
  )
}
