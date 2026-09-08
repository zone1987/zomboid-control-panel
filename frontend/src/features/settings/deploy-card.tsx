import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { CheckCircle2, PlugZap, Rocket, RefreshCw, TriangleAlert, XCircle } from 'lucide-react'

import { ApiError, errorField } from '@/lib/api'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { CredentialField } from './credential-field'
import { DeployHookInstructions } from './instructions'
import { DeployConfirm } from './deploy-confirm'
import { useDeploymentWatch } from './use-deployment-watch'
import {
  probeDeployHook,
  triggerDeployment,
  type DeployProbeResult,
  type DeployResult,
  type SettingState,
} from './settings'

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
  token,
  onUrl,
  onToken,
  onEnabled,
}: {
  urlState?: SettingState
  tokenState?: SettingState
  enabled: boolean
  url: string
  token: string
  onUrl: (value: string) => void
  onToken: (value: string) => void
  onEnabled: (value: boolean) => void
}) {
  const { t } = useTranslation()

  const [asking, setAsking] = useState(false)
  const watch = useDeploymentWatch()

  // Reads the platform and starts nothing, so it can be pressed to
  // answer a question rather than to commit to one.
  const check = useMutation<DeployProbeResult>({
    mutationFn: probeDeployHook,
    onSuccess: (result) =>
      result.state === 'ready' || result.state === 'noReadPermission'
        ? toast.success(t(result.messageKey))
        : toast.error(t(result.messageKey)),
    onError: () => toast.error(t('settings.deploy.probe.unreachable')),
  })

  const deploy = useMutation<DeployResult>({
    mutationFn: triggerDeployment,
    onSuccess: (result) => {
      toast.success(t('settings.deploy.queued'))
      watch.start(result.deploymentUuid ?? null)
    },
    onError: () => toast.error(t('settings.deploy.failed')),
  })

  // An HTTP code is a fact about the protocol, not an instruction. The
  // endpoint works out which setting is at fault and names it; this
  // shows that rather than making the operator decode a 403.
  const failure =
    deploy.error instanceof ApiError
      ? {
          message: errorField(deploy.error, 'message'),
          advice: errorField(deploy.error, 'advice'),
          status: (deploy.error.payload as { status?: number } | null)?.status ?? null,
          detail: errorField(deploy.error, 'detail'),
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
          value={token}
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

        {/* Two buttons, because they answer different questions: one
            reads the platform, the other replaces the panel. */}
        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            variant="outline"
            disabled={!configured || check.isPending}
            onClick={() => check.mutate()}
          >
            {check.isPending ? (
              <RefreshCw className="size-4 animate-spin" />
            ) : (
              <PlugZap className="size-4" />
            )}
            {t('settings.deploy.check')}
          </Button>

          <Button
            type="button"
            disabled={!configured || deploy.isPending || watch.phase !== 'idle'}
            onClick={() => setAsking(true)}
          >
            {deploy.isPending || watch.phase !== 'idle' ? (
              <RefreshCw className="size-4 animate-spin" />
            ) : (
              <Rocket className="size-4" />
            )}
            {t('settings.deploy.now')}
          </Button>
        </div>

        <DeployConfirm
          open={asking}
          onOpenChange={setAsking}
          onConfirm={() => {
            setAsking(false)
            deploy.mutate()
          }}
        />

        {check.data !== undefined && !check.isPending && (
          <Alert>
            {check.data.state === 'ready' || check.data.state === 'noReadPermission' ? (
              <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400" />
            ) : (
              <TriangleAlert className="size-4 text-amber-600 dark:text-amber-400" />
            )}
            <AlertTitle>{t(check.data.messageKey)}</AlertTitle>
            <AlertDescription className="space-y-1">
              {check.data.applicationName != null && (
                <p>
                  {t('settings.deploy.probe.application', {
                    name: check.data.applicationName,
                  })}
                  {check.data.applicationState != null && (
                    <span className="font-mono text-xs opacity-80">
                      {' '}
                      · {check.data.applicationState}
                    </span>
                  )}
                </p>
              )}
              {check.data.httpStatus != null && check.data.state !== 'ready' && (
                <p className="font-mono text-xs opacity-80">HTTP {check.data.httpStatus}</p>
              )}
            </AlertDescription>
          </Alert>
        )}

        {watch.phase !== 'idle' && (
          <Alert variant={watch.phase === 'failed' ? 'destructive' : undefined}>
            {watch.phase === 'failed' ? (
              <XCircle className="size-4" />
            ) : (
              <RefreshCw className="size-4 animate-spin" />
            )}
            <AlertTitle>{t(`settings.deploy.watch.${watch.phase}`)}</AlertTitle>
            <AlertDescription className="space-y-1">
              <p>{t('settings.deploy.watch.hint')}</p>
              {watch.reported != null && (
                <p className="font-mono text-xs opacity-80">{watch.reported}</p>
              )}
            </AlertDescription>
          </Alert>
        )}

        {deploy.isError && (
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
