import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronDown, Lock } from 'lucide-react'

import { Input } from '@/components/ui/input'
import { PasswordInput } from '@/components/ui/password-input'
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
    <div className="flex h-full flex-col space-y-2">
      {/* One line from `sm` up, where a second row of badges would push
          this field's input below its neighbour's in a two-column row.
          On a phone there is no second column, and not wrapping pushed
          the help button off the screen. */}
      <div className="flex min-h-6 flex-wrap items-center gap-2 sm:flex-nowrap sm:overflow-hidden">
        {/* The one thing on the line that may shrink: with every child
            `shrink-0` a long label pushed the row past the page. */}
        <Label htmlFor={id} className="min-w-0 truncate">
          {label}
        </Label>

        {state?.configured && !state.fromEnvironment && (
          <Badge variant="secondary" className="shrink-0 text-xs">
            {t('settings.configured')}
          </Badge>
        )}

        {/* "From the environment" already implies it is set, so showing
            both only crowds the line. */}
        {state?.fromEnvironment && (
          <Badge variant="outline" className="shrink-0 gap-1 text-xs" title={t('settings.environmentHint')}>
            <Lock className="size-3" />
            {t('settings.fromEnvironment')}
          </Badge>
        )}

        {instructions && (
          <Button
            type="button"
            variant="link"
            size="sm"
            className="h-8 shrink-0 p-0 text-xs sm:ml-auto sm:h-auto"
            onClick={() => setShowInstructions((previous) => !previous)}
          >
            {t('settings.howTo')}
            <ChevronDown
              className={`size-3 transition-transform ${showInstructions ? 'rotate-180' : ''}`}
            />
          </Button>
        )}
      </div>

      {state?.secret ? (
        <PasswordInput
          id={id}
          autoComplete="off"
          value={value}
          placeholder={state.configured ? t('settings.unchangedPlaceholder') : placeholder}
          onChange={(event) => onChange(event.target.value)}
        />
      ) : (
        <Input
          id={id}
          type="text"
          autoComplete="off"
          value={value}
          placeholder={placeholder}
          onChange={(event) => onChange(event.target.value)}
        />
      )}

      {state?.fromEnvironment && !showInstructions && (
        <p className="text-xs text-muted-foreground">{t('settings.environmentHint')}</p>
      )}

      {showInstructions && instructions && (
        <div className="rounded-md border bg-muted/40 p-3 text-sm">{instructions}</div>
      )}
    </div>
  )
}
