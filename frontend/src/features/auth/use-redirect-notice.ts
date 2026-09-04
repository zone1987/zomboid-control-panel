import { useEffect } from 'react'
import { useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

/**
 * OAuth and OpenID hand their outcome back as a query parameter, since
 * the browser leaves the app entirely and returns by redirect.
 */
const ERROR_KEYS: Record<string, string> = {
  'auth.steam.notLinked': 'auth.steamNotLinked',
  'auth.steam.unreachable': 'auth.steamUnreachable',
  'auth.steam.invalidResponse': 'auth.steamInvalidResponse',
  'auth.steam.rejected': 'auth.steamRejected',
  'auth.steam.incompleteResponse': 'auth.steamInvalidResponse',
  'auth.steam.failed': 'auth.providerFailed',
  'auth.google.notLinked': 'auth.googleNotLinked',
  'auth.google.failed': 'auth.providerFailed',
  'auth.google.notConfigured': 'auth.googleNotConfigured',
  'auth.identityTaken': 'auth.identityTaken',
  'auth.accountInactive': 'auth.accountInactive',
}

export function useRedirectNotice(): void {
  const [params, setParams] = useSearchParams()
  const { t } = useTranslation()

  useEffect(() => {
    const error = params.get('error')
    const linked = params.get('linked')

    if (!error && !linked) {
      return
    }

    if (error) {
      toast.error(t(ERROR_KEYS[error] ?? 'errors.generic'))
    } else {
      toast.success(t('profile.linkedSuccessfully'))
    }

    // Clear it so a reload does not repeat the message.
    const next = new URLSearchParams(params)
    next.delete('error')
    next.delete('linked')
    setParams(next, { replace: true })
  }, [params, setParams, t])
}
