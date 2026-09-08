import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { CheckCircle2, RefreshCw, XCircle } from 'lucide-react'

import { ApiError, errorField } from '@/lib/api'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { CredentialField } from './credential-field'
import { DeployHookInstructions } from './instructions'
import { testDeployHook, type DeployResult, type SettingState } from './settings'

/**
 * Updating the panel without anybody clicking anything.
 *
 * The call is made from here rather than from the release pipeline for
 * one reason: a deploy hook is as good as a login, so the platform's
 * address allow-list is worth keeping — and a CI runner has no fixed
 * address to put on it. This panel does.
 */
export function DeployCard({
  urlState,
  tokenState,
  enabled,
  url,
  onUrl,
  onToken,
  onEnabled,
}: {
  urlState?: SettingState
  tokenState?: SettingState
  enabled: boolean
  url: string
  onUrl: (value: string) => void
  onToken: (value: string) => void
  onEnabled: (value: boolean) => void
}) {
  const { t } = useTranslation()

  const test = useMutation<DeployResult>({
    mutationFn: testDeployHook,
    onSuccess: () => toast.success(t('settings.deploy.queued')),
    onError: () => toast.error(t('settings.deploy.failed')),
  })

  // An HTTP code is a fact about the protocol, not an instruction. The
  // endpoint works out which setting is at fault and names it; this
  // shows that rather than making the operator decode a 403.
  const failure =
    test.error instanceof ApiError
      ? {
          message: errorField(test.error, 'message'),
          advice: errorField(test.error, 'advice'),
          status: (test.error.payload as { status?: number } | null)?.status ?? null,
          detail: errorField(test.error, 'detail'),
        }
      : null

  const configured = urlState?.configured === true || url.trim() !== ''

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settings.deploy.title')}</CardTitle>
        <CardDescription>{t('settings.deploy.description')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <CredentialField
          id="deploy-webhook-url"
          label={t('settings.deploy.urlLabel')}
          placeholder="https://coolify.example.com/api/v1/deploy?uuid=…"
          state={urlState}
          value={url}
          onChange={onUrl}
          instructions={<DeployHookInstructions />}
        />

        <CredentialField
          id="deploy-webhook-token"
          label={t('settings.deploy.tokenLabel')}
          state={tokenState}
          value=""
          onChange={onToken}
        />

        {/* Off by default, and the label says what switching it on does:
            this restarts the operator's own panel. */}
        <div className="flex items-start gap-3 rounded-md border p-3">
          <Switch
            id="deploy-on-release"
            checked={enabled}
            disabled={!configured}
            onCheckedChange={onEnabled}
          />
          <div className="min-w-0 space-y-1">
            <Label htmlFor="deploy-on-release" className="font-normal">
              {t('settings.deploy.autoLabel')}
            </Label>
            <p className="text-sm text-muted-foreground">
              {configured ? t('settings.deploy.autoHint') : t('settings.deploy.needsUrl')}
            </p>
          </div>
        </div>

        {/* There is no way to ask a platform "would this work", so the
            button does the thing — and says so before it is pressed. */}
        {/* Stacked rather than beside the button: sharing a row left the
            warning 93px wide and 240px tall in a narrow frame. */}
        <div className="space-y-2">
          <p className="text-sm text-muted-foreground">{t('settings.deploy.testWarning')}</p>

          <Button
            type="button"
            variant="outline"
            disabled={!configured || test.isPending}
            onClick={() => test.mutate()}
          >
            <RefreshCw className={test.isPending ? 'size-4 animate-spin' : 'size-4'} />
            {t('settings.deploy.test')}
          </Button>
        </div>

        {test.data !== undefined && (
          <Alert>
            <CheckCircle2 className="size-4" />
            <AlertTitle>{t('settings.deploy.queued')}</AlertTitle>
            <AlertDescription>{t('settings.deploy.queuedHint')}</AlertDescription>
          </Alert>
        )}

        {test.isError && (
          <Alert variant="destructive">
            <XCircle className="size-4" />
            <AlertTitle>
              {failure?.message !== null && failure?.message !== undefined
                ? t(failure.message)
                : t('settings.deploy.failed')}
            </AlertTitle>
            <AlertDescription className="space-y-2">
              {failure?.advice != null && <p>{t(failure.advice)}</p>}

              {/* The platform's own words, last and quieter: they are
                  what to quote when asking somebody else, and rarely
                  what to act on. */}
              {(failure?.status != null || failure?.detail != null) && (
                <p className="font-mono text-xs opacity-80">
                  {failure.status != null && `HTTP ${failure.status}`}
                  {failure.status != null && failure.detail != null && ' — '}
                  {failure.detail}
                </p>
              )}
            </AlertDescription>
          </Alert>
        )}
      </CardContent>
    </Card>
  )
}
