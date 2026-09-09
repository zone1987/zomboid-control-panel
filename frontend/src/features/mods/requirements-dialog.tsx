import { useTranslation } from 'react-i18next'
import { Loader2 } from 'lucide-react'

import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { ModCover } from './mod-cover'
import { displayName, type Mod } from './mods'

/**
 * Asks before pulling extra mods into the list.
 *
 * A mod without its requirements loads and does nothing, so installing
 * them together is the useful default — but four new entries appearing
 * in somebody's list unannounced is not, which is why this is a
 * question rather than a silent addition.
 */
export function RequirementsDialog({
  mod,
  missing,
  truncated,
  pending,
  onConfirm,
  onCancel,
}: {
  mod: Mod | null
  missing: Mod[]
  truncated: boolean
  pending: boolean
  onConfirm: (withRequirements: boolean) => void
  onCancel: () => void
}) {
  const { t } = useTranslation()

  return (
    <Dialog open={mod !== null} onOpenChange={(open) => !open && onCancel()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {t('mods.requirementsTitle', { name: mod === null ? '' : displayName(mod) })}
          </DialogTitle>
          <DialogDescription>
            {t('mods.requirementsBody', { count: missing.length })}
          </DialogDescription>
        </DialogHeader>

        <ul className="max-h-64 space-y-2 overflow-y-auto">
          {missing.map((requirement) => (
            <li key={requirement.workshopId} className="flex items-center gap-2">
              <ModCover mod={requirement} className="size-8 shrink-0" />
              <span className="min-w-0 flex-1 truncate text-sm">
                {displayName(requirement)}
              </span>
            </li>
          ))}
        </ul>

        {truncated && (
          <p className="text-xs text-muted-foreground">{t('mods.truncated')}</p>
        )}

        <DialogFooter className="gap-2 sm:gap-2">
          {/* Adding it alone stays possible: an operator may know the
              requirement is met another way, and refusing would be the
              panel overruling them. */}
          <Button type="button" variant="outline" disabled={pending} onClick={() => onConfirm(false)}>
            {t('mods.addAlone')}
          </Button>

          <Button type="button" disabled={pending} onClick={() => onConfirm(true)}>
            {pending && <Loader2 className="size-4 animate-spin" />}
            {t('mods.addWithRequirements', { count: missing.length })}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
