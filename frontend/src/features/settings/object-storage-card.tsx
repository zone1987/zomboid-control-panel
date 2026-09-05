import { useMutation } from '@tanstack/react-query'
import { Trans, useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { CheckCircle2, Database, HardDrive, HelpCircle } from 'lucide-react'

import { errorField } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { CredentialField } from './credential-field'
import { HetznerInstructions } from './instructions'
import { SETTING_KEYS, testObjectStorage, type SettingKey, type SettingState } from './settings'

export function ObjectStorageCard({
  items,
  field,
}: {
  items: Record<string, SettingState> | undefined
  field: (key: SettingKey) => {
    state: SettingState | undefined
    value: string
    onChange: (value: string) => void
  }
}) {
  const { t } = useTranslation()

  const probe = useMutation({
    mutationFn: testObjectStorage,
    onSuccess: (result) => toast.success(t('settings.s3Works', { bucket: result.bucket })),
    onError: (error) => {
      if (errorField(error, 'error') === 'settings.s3Incomplete') {
        toast.error(t('settings.s3Incomplete'))

        return
      }

      const detail = errorField(error, 'detail')

      toast.error(detail ? `${t('settings.s3Rejected')}: ${detail}` : t('settings.s3Rejected'))
    },
  })

  const required = [
    { key: SETTING_KEYS.s3Endpoint, label: t('settings.s3Endpoint') },
    { key: SETTING_KEYS.s3Region, label: t('settings.s3Region') },
    { key: SETTING_KEYS.s3Bucket, label: t('settings.s3Bucket') },
    { key: SETTING_KEYS.s3AccessKey, label: t('settings.s3AccessKey') },
    { key: SETTING_KEYS.s3SecretKey, label: t('settings.s3SecretKey') },
  ]

  const missing = required.filter((entry) => !items?.[entry.key]?.configured)
  const complete = missing.length === 0

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settings.s3Title')}</CardTitle>
        <CardDescription>{t('settings.s3Description')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <Alert>
          <Database className="size-4" />
          <AlertTitle>{t('settings.s3WhatFor')}</AlertTitle>
          <AlertDescription>{t('settings.s3WhatForBody')}</AlertDescription>
        </Alert>

        <Alert>
          <HardDrive className="size-4" />
          <AlertTitle>{t('settings.s3HowBig')}</AlertTitle>
          <AlertDescription>
            <Trans i18nKey="settings.s3HowBigBody" components={{ 1: <strong /> }} />
          </AlertDescription>
        </Alert>

        <Dialog>
          <DialogTrigger asChild>
            <Button type="button" variant="outline" size="sm">
              <HelpCircle className="size-4" />
              {t('settings.s3Where')}
            </Button>
          </DialogTrigger>

          <DialogContent className="sm:max-w-lg">
            <DialogHeader>
              <DialogTitle>{t('settings.s3Where')}</DialogTitle>
              <DialogDescription>{t('settings.s3WhereIntro')}</DialogDescription>
            </DialogHeader>

            <div className="text-sm">
              <HetznerInstructions />
            </div>
          </DialogContent>
        </Dialog>

        <div className="grid gap-4 sm:grid-cols-2">
          <CredentialField
            id="s3-endpoint"
            label={t('settings.s3Endpoint')}
            placeholder="nbg1.your-objectstorage.com"
            {...field(SETTING_KEYS.s3Endpoint)}
          />

          <CredentialField
            id="s3-region"
            label={t('settings.s3Region')}
            placeholder="nbg1"
            {...field(SETTING_KEYS.s3Region)}
          />

          <CredentialField
            id="s3-bucket"
            label={t('settings.s3Bucket')}
            placeholder="zomboid-tiles"
            {...field(SETTING_KEYS.s3Bucket)}
          />

          <CredentialField
            id="s3-access-key"
            label={t('settings.s3AccessKey')}
            {...field(SETTING_KEYS.s3AccessKey)}
          />

          <CredentialField
            id="s3-secret-key"
            label={t('settings.s3SecretKey')}
            {...field(SETTING_KEYS.s3SecretKey)}
          />
        </div>

        <div className="flex items-center gap-3">
          <Button
            type="button"
            variant="secondary"
            disabled={probe.isPending || !complete}
            onClick={() => probe.mutate()}
          >
            {probe.isPending ? t('common.loading') : t('settings.s3Test')}
          </Button>

          {probe.isSuccess && !probe.isPending && (
            <span className="flex items-center gap-1 text-sm text-emerald-600 dark:text-emerald-500">
              <CheckCircle2 className="size-4" />
              {t('settings.s3Verified')}
            </span>
          )}
        </div>

        {/* The button is disabled until all five are stored: a probe
            against a half-configured store answers with a signature
            error, which reads like a wrong key. */}
        {!complete && (
          <p className="text-xs text-muted-foreground">
            {t('settings.s3StillMissing', {
              fields: missing.map((entry) => entry.label).join(', '),
            })}
          </p>
        )}
      </CardContent>
    </Card>
  )
}
