import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ExternalLink } from 'lucide-react'

import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { CreditsContent } from './credits-content'

/**
 * The attribution, reachable from the status bar without leaving the page.
 *
 * The dialog is the convenience; the page is the notice. The Indie
 * Stone's terms ask for a visible one, and a dialog cannot be linked to
 * or found by somebody who was never shown it — so this one carries a
 * link to `/credits`, which stays.
 */
export function CreditsDialog() {
  const { t } = useTranslation()

  return (
    <Dialog>
      <DialogTrigger asChild>
        <Button variant="ghost" size="sm" className="h-8 px-2 text-xs font-normal sm:h-6">
          {t('nav.credits')}
        </Button>
      </DialogTrigger>

      <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>{t('credits.title')}</DialogTitle>
          <DialogDescription>{t('credits.description')}</DialogDescription>
        </DialogHeader>

        <CreditsContent className="[&_section]:max-w-none" />

        <Link
          to="/credits"
          className="inline-flex items-center gap-1.5 self-start text-sm text-primary hover:underline"
        >
          {t('credits.openPage')}
          <ExternalLink className="size-3" />
        </Link>
      </DialogContent>
    </Dialog>
  )
}
