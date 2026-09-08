import { useState, type ReactNode } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ChevronDown, Lock } from 'lucide-react'

import { Input } from '@/components/ui/input'
import { PasswordInput } from '@/components/ui/password-input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/auth-context'
import { revealSecret, type SettingState } from './settings'

export function CredentialField({
  id,
  label,
  name,
  state,
  value,
  onChange,
  placeholder,
  instructions,
}: {
  id: string
  label: string
  /** The setting key, so a stored secret can be read back. */
  name?: string
  state: SettingState | undefined
  value: string
  onChange: (value: string) => void
  placeholder?: string
  instructions?: ReactNode
}) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [showInstructions, setShowInstructions] = useState(false)

  const mayEdit = can('settings.edit')

  /**
   * Puts the stored secret into the field on first focus.
   *
   * Otherwise an operator could never see what they had entered, and
   * the only way to check a token was to paste it again. It travels
   * only when the field is actually touched, not on every page view.
   */
  const reveal = useMutation({
    mutationFn: () => revealSecret(name ?? ''),
    onSuccess: (answer) => {
      if (answer.value !== null) {
        onChange(answer.value)
      }
    },
    onError: () => toast.error(t('settings.revealFailed')),
  })

  const fetchStoredValue = () => {
    if (
      name === undefined
      || value !== ''
      || state?.configured !== true
      || state.fromEnvironment
      || !mayEdit
      || reveal.isPending
    ) {
      return
    }

    reveal.mutate()
  }

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
          disabled={!mayEdit || reveal.isPending}
          value={value}
          placeholder={state.configured ? t('settings.unchangedPlaceholder') : placeholder}
          onChange={(event) => onChange(event.target.value)}
          onFocus={fetchStoredValue}
        />
      ) : (
        <Input
          id={id}
          type="text"
          autoComplete="off"
          disabled={!mayEdit}
          value={value}
          placeholder={placeholder}
          onChange={(event) => onChange(event.target.value)}
        />
      )}

      {!mayEdit && (
        <p className="text-muted-foreground text-xs">{t('settings.noPermission')}</p>
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
