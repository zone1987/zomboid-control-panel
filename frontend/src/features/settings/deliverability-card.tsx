import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { AlertTriangle, Check, Copy, ShieldCheck, X } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { checkDeliverability, type DeliverabilityCheck, type DeliverabilityReport } from './settings'

const VERDICT_VARIANT = {
  good: 'default',
  partial: 'secondary',
  atRisk: 'destructive',
  noSender: 'outline',
} as const

export function DeliverabilityCard() {
  const { t } = useTranslation()
  const [report, setReport] = useState<DeliverabilityReport | null>(null)

  const probe = useMutation({
    mutationFn: checkDeliverability,
    onSuccess: setReport,
    onError: () => toast.error(t('errors.generic')),
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settings.deliverabilityTitle')}</CardTitle>
        <CardDescription>{t('settings.deliverabilityDescription')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <Button
          variant="outline"
          size="sm"
          disabled={probe.isPending}
          onClick={() => probe.mutate()}
        >
          <ShieldCheck className="size-4" />
          {probe.isPending ? t('common.loading') : t('settings.checkDeliverability')}
        </Button>

        {report && (
          <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
              <Badge variant={VERDICT_VARIANT[report.verdict]}>
                {t(`settings.verdict.${report.verdict}`)}
              </Badge>

              {report.domain && (
                <span className="text-sm text-muted-foreground">
                  {t('settings.deliverabilityDomain', { domain: report.domain })}
                </span>
              )}
            </div>

            {report.verdict === 'noSender' ? (
              <p className="text-sm text-muted-foreground">{t('settings.deliverabilityNoSender')}</p>
            ) : (
              <div className="space-y-3">
                {report.checks.map((check) => (
                  <CheckRow key={check.id} check={check} />
                ))}
              </div>
            )}

            <p className="text-xs text-muted-foreground">{t('settings.deliverabilityDnsHint')}</p>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function CheckRow({ check }: { check: DeliverabilityCheck }) {
  const { t } = useTranslation()

  const icon = {
    ok: <Check className="mt-0.5 size-4 shrink-0 text-emerald-500" />,
    warning: <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-500" />,
    missing: <X className="mt-0.5 size-4 shrink-0 text-destructive" />,
  }[check.status]

  return (
    <div className="rounded-md border p-3">
      <div className="flex gap-2">
        {icon}

        <div className="min-w-0 flex-1 space-y-2">
          <div>
            <p className="text-sm font-medium">{t(`settings.record.${check.id}`)}</p>
            <p className="text-sm text-muted-foreground">{t(`settings.reason.${check.reason}`)}</p>
          </div>

          {check.found && check.status !== 'ok' && (
            <ValueBlock label={t('settings.deliverabilityFound')} value={check.found} />
          )}

          {check.status === 'ok' && check.found && (
            <p className="text-xs text-muted-foreground">
              {t('settings.deliverabilityFound')}{' '}
              <code className="break-all font-mono">{check.found}</code>
            </p>
          )}

          {check.suggestedValue && check.recordName && (
            <div className="space-y-1">
              <p className="text-xs font-medium">{t('settings.deliverabilitySuggestion')}</p>
              <ValueBlock label={t('settings.recordName')} value={check.recordName} />
              <ValueBlock
                label={t('settings.recordValue')}
                value={check.suggestedValue}
                copyable
              />
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

function ValueBlock({
  label,
  value,
  copyable = false,
}: {
  label: string
  value: string
  copyable?: boolean
}) {
  const { t } = useTranslation()

  return (
    <div className="flex items-start gap-2">
      <span className="w-16 shrink-0 pt-1 text-xs text-muted-foreground">{label}</span>

      <code className="min-w-0 flex-1 break-all rounded bg-muted px-2 py-1 font-mono text-xs">
        {value}
      </code>

      {copyable && (
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="size-7 shrink-0"
          title={t('common.copy')}
          onClick={() => {
            void navigator.clipboard.writeText(value)
            toast.success(t('common.copied'))
          }}
        >
          <Copy className="size-3.5" />
        </Button>
      )}
    </div>
  )
}
