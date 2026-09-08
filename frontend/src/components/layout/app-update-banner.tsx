import { useTranslation } from 'react-i18next'
import { RefreshCw } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { useAppUpdate } from '@/features/panel/use-app-update'

/**
 * Offers a reload once a newer build is waiting.
 *
 * A button in the top bar rather than a dialog: nothing is broken, and
 * interrupting an operator mid-action to announce good news is rude.
 */
export function AppUpdateBanner() {
  const { t } = useTranslation()
  const { ready, apply } = useAppUpdate()

  if (!ready) {
    return null
  }

  return (
    <Button
      variant="outline"
      size="sm"
      // The label is ~150px, which on a phone pushed the language and
      // theme buttons off the right edge of the bar.
      className="size-9 gap-1.5 p-0 sm:h-8 sm:w-auto sm:px-3"
      title={t('panel.reloadForUpdate')}
      aria-label={t('panel.reloadForUpdate')}
      onClick={apply}
    >
      <RefreshCw className="size-3.5" />
      <span className="hidden sm:inline">{t('panel.reloadForUpdate')}</span>
    </Button>
  )
}
