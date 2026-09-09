import { useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
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

  // A stored secret is fetched with the page rather than on focus:
  // focus is not a gesture anybody makes to *read*, so the field
  // looked empty and a saved key looked lost. See rule 6f.
  const readable =
    name !== undefined
    && state?.secret === true
    && state.configured
    && !state.fromEnvironment
    && mayEdit

  const stored = useQuery({
    queryKey: ['settings', 'reveal', name],
    queryFn: () => revealSecret(name ?? ''),
    enabled: readable,
    staleTime: Number.POSITIVE_INFINITY,
  })

  // Derived, never copied into state: an effect writing the answer into
  // the draft would overwrite whatever somebody is typing on every
  // refetch (rule 10g2). The edit wins while it exists.
  const shown = value !== '' ? value : (stored.data?.value ?? '')

  // "Could not read it" is its own state and must not be drawn as an
  // empty field, which would read as "nothing is stored" (rule 6c).
  const unreadable = readable && stored.isError

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
          <Badge variant="success" className="shrink-0 text-xs">
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
          // `isFetching`, not `isPending`: a query held back by
          // `enabled` stays pending forever, which would disable the
          // field of every secret that is not stored yet.
          disabled={!mayEdit || stored.isFetching}
          value={shown}
          placeholder={state.configured ? t('settings.unchangedPlaceholder') : placeholder}
          onChange={(event) => onChange(event.target.value)}
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

      {unreadable && (
        <p className="text-xs text-destructive">{t('settings.revealFailed')}</p>
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
