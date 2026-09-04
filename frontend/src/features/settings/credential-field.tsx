import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronDown, Lock } from 'lucide-react'

import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import type { SettingState } from './settings'

export function CredentialField({
  id,
  label,
  state,
  value,
  onChange,
  placeholder,
  instructions,
}: {
  id: string
  label: string
  state: SettingState | undefined
  value: string
  onChange: (value: string) => void
  placeholder?: string
  instructions?: ReactNode
}) {
  const { t } = useTranslation()
  const [showInstructions, setShowInstructions] = useState(false)

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-2">
        <Label htmlFor={id}>{label}</Label>

        {state?.configured && (
          <Badge variant="secondary" className="text-xs">
            {t('settings.configured')}
          </Badge>
        )}

        {state?.fromEnvironment && (
          <Badge variant="outline" className="gap-1 text-xs">
            <Lock className="size-3" />
            {t('settings.fromEnvironment')}
          </Badge>
        )}

        {instructions && (
          <Button
            type="button"
            variant="link"
            size="sm"
            className="ml-auto h-auto p-0 text-xs"
            onClick={() => setShowInstructions((previous) => !previous)}
          >
            {t('settings.howTo')}
            <ChevronDown
              className={`size-3 transition-transform ${showInstructions ? 'rotate-180' : ''}`}
            />
          </Button>
        )}
      </div>

      <Input
        id={id}
        type={state?.secret ? 'password' : 'text'}
        autoComplete="off"
        value={value}
        placeholder={
          state?.secret && state.configured ? t('settings.unchangedPlaceholder') : placeholder
        }
        onChange={(event) => onChange(event.target.value)}
      />

      {state?.fromEnvironment && (
        <p className="text-xs text-muted-foreground">{t('settings.environmentHint')}</p>
      )}

      {showInstructions && instructions && (
        <div className="rounded-md border bg-muted/40 p-3 text-sm">{instructions}</div>
      )}
    </div>
  )
}
