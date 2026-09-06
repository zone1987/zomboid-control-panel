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
    <Button variant="outline" size="sm" className="h-8 gap-1.5" onClick={apply}>
      <RefreshCw className="size-3.5" />
      {t('panel.reloadForUpdate')}
    </Button>
  )
}
