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
import { TabGroupLabel } from '@/components/ui/tab-group-label'
import { RetentionCard } from './retention-card'
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
import { VehicleModelsCard } from './vehicle-models-card'
import { IconPacksCard } from './icon-packs-card'
import {
  DiscordInstructions,
  GoogleOAuthInstructions,
  MailerInstructions,
  SteamKeyInstructions,
} from './instructions'
import {
  listSettings,
  MAIL_PRESETS,
  SETTING_KEYS,
  testDiscordToken,
  testMail,
  testSteamKey,
  updateSettings,
  type SettingKey,
} from './settings'

type Draft = Partial<Record<SettingKey, string>>

/** The tabs that hold editable fields; the rest upload files instead. */
const SAVABLE_TABS = ['steam', 'google', 'discord', 'mail', 'privacy']

export function SettingsPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [draft, setDraft] = useState<Draft>({})
  const [tab, setTab] = useState('steam')

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

  const probeDiscord = useMutation({
    mutationFn: testDiscordToken,
    // The bot's own name is the useful half: it proves the token
    // belongs to the application the operator meant, not merely that it
    // is a valid token somewhere.
    onSuccess: (result) => toast.success(t('settings.discordTokenWorks', { name: result.bot })),
    onError: (error) => {
      if (error instanceof ApiError && error.status === 409) {
        toast.error(t('discord.noToken'))

        return
      }

      const body = error instanceof ApiError ? (error.payload as { error?: string }) : undefined

      toast.error(body?.error === undefined ? t('discord.refused') : t(body.error))
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
  // Only the credential tabs have fields to save. On the file tabs an
  // upload happens as soon as a file is dropped, so a save button there
  // would suggest the transfer still needed confirming.
  const savable = SAVABLE_TABS.includes(tab)
  const redirectUri = data?.googleRedirectUri ?? ''

  if (isPending) {
    return <Skeleton className="h-96 w-full" />
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('nav.settings')}</h1>
        <p className="text-muted-foreground">{t('settings.description')}</p>
      </div>

      {/* A rail beside the cards rather than a strip above them: the
          groups then read as sections with their tabs under them, and a
          new category lengthens the list instead of wrapping a lone tab
          onto a second row. Stacks above the content below `sm`. */}
      <Tabs
        value={tab}
        onValueChange={setTab}
        orientation="vertical"
        className="gap-6 sm:grid sm:grid-cols-[12rem_1fr] sm:items-start"
      >
        <TabsList className="h-auto w-full flex-col items-stretch gap-0.5 bg-transparent p-0">
          <TabGroupLabel>{t('settings.groupAccess')}</TabGroupLabel>
          <TabsTrigger value="steam" className="justify-start">
            Steam
          </TabsTrigger>
          <TabsTrigger value="google" className="justify-start">
            Google
          </TabsTrigger>
          <TabsTrigger value="discord" className="justify-start">
            Discord
          </TabsTrigger>
          <TabsTrigger value="mail" className="justify-start">
            {t('settings.mailTab')}
          </TabsTrigger>

          <TabGroupLabel className="mt-3">{t('settings.groupGameContent')}</TabGroupLabel>
          <TabsTrigger value="icons" className="justify-start">
            {t('settings.iconsTab')}
          </TabsTrigger>
          <TabsTrigger value="vehicles" className="justify-start">
            {t('settings.vehiclesTab')}
          </TabsTrigger>

          <TabGroupLabel className="mt-3">{t('settings.groupPrivacy')}</TabGroupLabel>
          <TabsTrigger value="privacy" className="justify-start">
            {t('settings.privacyTab')}
          </TabsTrigger>
        </TabsList>

        <div className="min-w-0">

        <TabsContent value="privacy">
          <RetentionCard {...field(SETTING_KEYS.playerRetentionDays)} />
        </TabsContent>

        <TabsContent value="discord">
          <Card>
            <CardHeader>
              <CardTitle>{t('settings.discordTitle')}</CardTitle>
              <CardDescription>{t('settings.discordDescription')}</CardDescription>
            </CardHeader>

            <CardContent className="space-y-4">
              <CredentialField
                id="discord-application-id"
                label={t('settings.discordApplicationId')}
                placeholder="1234567890123456789"
                instructions={
                  <DiscordInstructions interactionUrl={data?.discordInteractionUrl ?? ''} />
                }
                {...field(SETTING_KEYS.discordApplicationId)}
              />

              <CredentialField
                id="discord-public-key"
                label={t('settings.discordPublicKey')}
                placeholder="a1b2c3…"
                {...field(SETTING_KEYS.discordPublicKey)}
              />

              <CredentialField
                id="discord-bot-token"
                label={t('settings.discordBotToken')}
                {...field(SETTING_KEYS.discordBotToken)}
              />

              <Button
                variant="outline"
                size="sm"
                disabled={
                  probeDiscord.isPending || !data?.items[SETTING_KEYS.discordBotToken]?.configured
                }
                onClick={() => probeDiscord.mutate()}
              >
                <PlugZap className="size-4" />
                {probeDiscord.isPending ? t('common.loading') : t('settings.testToken')}
              </Button>

              <p className="text-muted-foreground text-xs">{t('settings.discordNextStep')}</p>
            </CardContent>
          </Card>
        </TabsContent>

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

        <TabsContent value="vehicles">
          <VehicleModelsCard />
        </TabsContent>

          {/* Inside the content column, so it sits under the card it
              saves rather than under the rail. */}
          {savable && (
            <div className="mt-4 flex gap-2">
              <Button disabled={!hasChanges || save.isPending} onClick={() => save.mutate(draft)}>
                {save.isPending ? t('common.loading') : t('common.save')}
              </Button>

              {hasChanges && (
                <Button variant="ghost" onClick={() => setDraft({})}>
                  {t('common.cancel')}
                </Button>
              )}
            </div>
          )}
        </div>
      </Tabs>
    </div>
  )
}
