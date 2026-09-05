import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { MailCheck, PlugZap } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Label } from '@/components/ui/label'
import { CredentialField } from './credential-field'
import { DeliverabilityCard } from './deliverability-card'
import { IconPacksCard } from './icon-packs-card'
import { ObjectStorageCard } from './object-storage-card'
import { TexturePacksCard } from './texture-packs-card'
import { GoogleOAuthInstructions, MailerInstructions, SteamKeyInstructions } from './instructions'
import {
  listSettings,
  MAIL_PRESETS,
  SETTING_KEYS,
  testMail,
  testSteamKey,
  updateSettings,
  type SettingKey,
} from './settings'

type Draft = Partial<Record<SettingKey, string>>

export function SettingsPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [draft, setDraft] = useState<Draft>({})

  const { data, isPending } = useQuery({
    queryKey: ['settings'],
    queryFn: listSettings,
  })

  const save = useMutation({
    mutationFn: (values: Draft) => updateSettings(values),
    onSuccess: async () => {
      setDraft({})
      await queryClient.invalidateQueries({ queryKey: ['settings'] })
      toast.success(t('settings.saved'))
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError && error.status === 403
          ? t('errors.forbidden')
          : t('errors.generic'),
      )
    },
  })

  const probeMail = useMutation({
    mutationFn: async () => {
      // Testing the stored settings while the form holds newer ones would
      // check the wrong server, so pending edits are saved first.
      if (hasChanges) {
        await updateSettings(draft)
        setDraft({})
        await queryClient.invalidateQueries({ queryKey: ['settings'] })
      }

      return testMail()
    },
    onSuccess: (result) => toast.success(t('settings.mailSent', { recipient: result.recipient })),
    onError: (error) => {
      const detail =
        error instanceof ApiError && typeof error.payload === 'object' && error.payload !== null
          ? ((error.payload as { detail?: string }).detail ?? '')
          : ''

      toast.error(detail === '' ? t('settings.mailFailed') : `${t('settings.mailFailed')} ${detail}`, {
        duration: 12_000,
      })
    },
  })

  const probeSteam = useMutation({
    mutationFn: testSteamKey,
    onSuccess: (result) => toast.success(t('settings.steamKeyWorks', { name: result.sample })),
    onError: (error) => {
      if (error instanceof ApiError && error.status === 409) {
        toast.error(t('settings.steamKeyMissing'))

        return
      }

      toast.error(t('settings.steamKeyRejected'))
    },
  })

  const field = (key: SettingKey) => ({
    state: data?.items[key],
    value: draft[key] ?? (data?.items[key]?.secret ? '' : (data?.items[key]?.value ?? '')),
    onChange: (value: string) => setDraft((previous) => ({ ...previous, [key]: value })),
  })

  const hasChanges = Object.keys(draft).length > 0
  const redirectUri = data?.googleRedirectUri ?? ''

  if (isPending) {
    return <Skeleton className="h-96 w-full" />
  }

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('nav.settings')}</h1>
        <p className="text-muted-foreground">{t('settings.description')}</p>
      </div>

      <Tabs defaultValue="steam">
        <TabsList>
          <TabsTrigger value="steam">Steam</TabsTrigger>
          <TabsTrigger value="google">Google</TabsTrigger>
          <TabsTrigger value="mail">{t('settings.mailTab')}</TabsTrigger>
          <TabsTrigger value="icons">{t('settings.iconsTab')}</TabsTrigger>
          <TabsTrigger value="map">{t('settings.mapTab')}</TabsTrigger>
          <TabsTrigger value="storage">{t('settings.storageTab')}</TabsTrigger>
        </TabsList>

        <TabsContent value="steam">
          <Card>
            <CardHeader>
              <CardTitle>{t('settings.steamTitle')}</CardTitle>
              <CardDescription>{t('settings.steamDescription')}</CardDescription>
            </CardHeader>

            <CardContent className="space-y-4">
              <CredentialField
                id="steam-api-key"
                label={t('settings.steamApiKey')}
                placeholder="E1B2C3D4E5F6..."
                instructions={<SteamKeyInstructions />}
                {...field(SETTING_KEYS.steamApiKey)}
              />

              <Button
                variant="outline"
                size="sm"
                disabled={probeSteam.isPending || !data?.items[SETTING_KEYS.steamApiKey]?.configured}
                onClick={() => probeSteam.mutate()}
              >
                <PlugZap className="size-4" />
                {probeSteam.isPending ? t('common.loading') : t('settings.testKey')}
              </Button>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="google">
          <Card>
            <CardHeader>
              <CardTitle>{t('settings.googleTitle')}</CardTitle>
              <CardDescription>{t('settings.googleDescription')}</CardDescription>
            </CardHeader>

            <CardContent className="space-y-4">
              <CredentialField
                id="google-client-id"
                label={t('settings.googleClientId')}
                placeholder="123456789-abc.apps.googleusercontent.com"
                instructions={<GoogleOAuthInstructions redirectUri={redirectUri} />}
                {...field(SETTING_KEYS.googleClientId)}
              />

              <CredentialField
                id="google-client-secret"
                label={t('settings.googleClientSecret')}
                {...field(SETTING_KEYS.googleClientSecret)}
              />
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="mail">
          <Card>
            <CardHeader>
              <CardTitle>{t('settings.mailTitle')}</CardTitle>
              <CardDescription>{t('settings.mailDescription')}</CardDescription>
            </CardHeader>

            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label>{t('settings.mailProvider')}</Label>
                <div className="flex flex-wrap gap-2">
                  {MAIL_PRESETS.map((preset) => (
                    <Button
                      key={preset.id}
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() =>
                        setDraft((previous) => ({
                          ...previous,
                          [SETTING_KEYS.mailHost]: preset.host,
                          [SETTING_KEYS.mailPort]: preset.port,
                          [SETTING_KEYS.mailEncryption]: preset.encryption,
                        }))
                      }
                    >
                      {preset.label}
                    </Button>
                  ))}
                </div>
                <p className="text-xs text-muted-foreground">{t('settings.mailProviderHint')}</p>
              </div>

              <div className="grid items-start gap-4 sm:grid-cols-[1fr_8rem]">
                <CredentialField
                  id="mail-host"
                  label={t('settings.mailHost')}
                  placeholder="smtp.example.com"
                  instructions={<MailerInstructions />}
                  {...field(SETTING_KEYS.mailHost)}
                />

                <CredentialField
                  id="mail-port"
                  label={t('settings.mailPort')}
                  placeholder="587"
                  {...field(SETTING_KEYS.mailPort)}
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="mail-encryption">{t('settings.mailEncryption')}</Label>
                <Select
                  value={String(
                    draft[SETTING_KEYS.mailEncryption] ??
                      data?.items[SETTING_KEYS.mailEncryption]?.value ??
                      'tls',
                  )}
                  onValueChange={(value) =>
                    setDraft((previous) => ({ ...previous, [SETTING_KEYS.mailEncryption]: value }))
                  }
                >
                  <SelectTrigger id="mail-encryption">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="tls">{t('settings.encryptionTls')}</SelectItem>
                    <SelectItem value="ssl">{t('settings.encryptionSsl')}</SelectItem>
                    <SelectItem value="none">{t('settings.encryptionNone')}</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1">
                <CredentialField
                  id="mail-username"
                  label={t('settings.mailUsername')}
                  placeholder="name@example.com"
                  {...field(SETTING_KEYS.mailUsername)}
                />
                <p className="text-xs text-muted-foreground">{t('settings.mailUsernameHint')}</p>
              </div>

              <CredentialField
                id="mail-password"
                label={t('settings.mailPassword')}
                {...field(SETTING_KEYS.mailPassword)}
              />

              <div className="grid items-start gap-4 sm:grid-cols-2">
                <CredentialField
                  id="mail-from-address"
                  label={t('settings.mailFromAddress')}
                  placeholder="noreply@example.com"
                  {...field(SETTING_KEYS.mailFromAddress)}
                />

                <CredentialField
                  id="mail-from-name"
                  label={t('settings.mailFromName')}
                  placeholder="ZomboidControl"
                  {...field(SETTING_KEYS.mailFromName)}
                />
              </div>

              <Button
                variant="outline"
                size="sm"
                disabled={probeMail.isPending}
                onClick={() => probeMail.mutate()}
              >
                <MailCheck className="size-4" />
                {probeMail.isPending ? t('common.loading') : t('settings.sendTestMail')}
              </Button>
            </CardContent>
          </Card>

          <DeliverabilityCard />
        </TabsContent>

        <TabsContent value="icons">
          <IconPacksCard />
        </TabsContent>

        <TabsContent value="map">
          <TexturePacksCard />
        </TabsContent>

        <TabsContent value="storage">
          <ObjectStorageCard items={data?.items} field={field} />
        </TabsContent>
      </Tabs>

      <div className="flex gap-2">
        <Button disabled={!hasChanges || save.isPending} onClick={() => save.mutate(draft)}>
          {save.isPending ? t('common.loading') : t('common.save')}
        </Button>

        {hasChanges && (
          <Button variant="ghost" onClick={() => setDraft({})}>
            {t('common.cancel')}
          </Button>
        )}
      </div>
    </div>
  )
}
