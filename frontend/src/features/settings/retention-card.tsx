import { useTranslation } from 'react-i18next'
import { Trash2 } from 'lucide-react'

import { Alert, AlertDescription } from '@/components/ui/alert'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import type { SettingState } from './settings'

/** Below this a purge would catch somebody who is merely on holiday. */
const MINIMUM_DAYS = 7

/**
 * How long a player nobody has seen is kept.
 *
 * The purge exists in the backend and ran on a schedule with no way to
 * configure it, so it was permanently off and nothing said so. Off is
 * still the default and a valid answer: the operator decides, not the
 * panel.
 *
 * What is deliberately *not* offered is a horizon for the ban list. A ban
 * that expires because nobody logged in stops being a ban.
 */
export function RetentionCard({
  state,
  value,
  onChange,
}: {
  state?: SettingState
  value: string
  onChange: (value: string) => void
}) {
  const { t } = useTranslation()

  const days = Number.parseInt(value, 10)
  const tooShort = value.trim() !== '' && (!Number.isFinite(days) || days < MINIMUM_DAYS)

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settings.retentionTitle')}</CardTitle>
        <CardDescription>{t('settings.retentionDescription')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <div className="max-w-xs space-y-1.5">
          <Label htmlFor="player-retention">{t('settings.retentionDays')}</Label>
          <Input
            id="player-retention"
            inputMode="numeric"
            placeholder={t('settings.retentionOff')}
            aria-invalid={tooShort}
            aria-describedby="player-retention-hint"
            value={value}
            onChange={(event) => onChange(event.target.value)}
          />
          <p id="player-retention-hint" className="text-xs text-muted-foreground">
            {value.trim() === ''
              ? t('settings.retentionIsOff')
              : tooShort
                ? t('settings.retentionTooShort', { count: MINIMUM_DAYS })
                : t('settings.retentionIsOn', { count: days })}
          </p>
        </div>

        {/* Said plainly, because the control deletes things when it comes
            round and the operator is the one answering for it. */}
        <Alert>
          <Trash2 className="size-4" />
          <AlertDescription>
            <span className="block">{t('settings.retentionWhatGoes')}</span>
            <span className="block font-medium">{t('settings.retentionWhatStays')}</span>
          </AlertDescription>
        </Alert>

        {state?.fromEnvironment === true && (
          <p className="text-xs text-muted-foreground">{t('settings.fromEnvironment')}</p>
        )}
      </CardContent>
    </Card>
  )
}
