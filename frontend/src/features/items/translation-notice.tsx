import { useTranslation } from 'react-i18next'
import { TriangleAlert } from 'lucide-react'

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { TRANSLATION_FAULTS, type TranslationVerdict } from './items'

/**
 * Says why the item names are English when they should not be.
 *
 * Without it the page showed English names and said nothing, and the
 * four causes -- wrong path, refused login, unreadable file, no
 * credentials -- were indistinguishable from the benign case of a
 * language the game does not ship.
 */
export function TranslationNotice({ verdict }: { verdict?: TranslationVerdict }) {
  const { t } = useTranslation()

  if (verdict === undefined || !TRANSLATION_FAULTS.includes(verdict.state)) {
    return null
  }

  return (
    <Alert>
      <TriangleAlert className="size-4 text-amber-600 dark:text-amber-400" />
      <AlertTitle>{t('items.translationTitle')}</AlertTitle>
      <AlertDescription className="space-y-1">
        <p>{t(`items.translation_${verdict.state}`)}</p>
        {verdict.path !== null && (
          <p className="text-xs">
            {t('items.translationPath')}{' '}
            <code className="break-all font-mono">{verdict.path}</code>
          </p>
        )}
      </AlertDescription>
    </Alert>
  )
}
