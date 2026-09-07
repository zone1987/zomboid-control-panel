import { Trans, useTranslation } from 'react-i18next'
import { ExternalLink } from 'lucide-react'

import { Copyable } from '@/components/ui/copyable'


function StepList({ items }: { items: string[] }) {
  return (
    <ol className="ml-4 list-decimal space-y-1">
      {items.map((step) => (
        <li key={step}>{step}</li>
      ))}
    </ol>
  )
}

function DocLink({ href, children }: { href: string; children: string }) {
  return (
    <a
      href={href}
      target="_blank"
      rel="noreferrer noopener"
      className="inline-flex items-center gap-1 font-medium underline underline-offset-2"
    >
      {children}
      <ExternalLink className="size-3" />
    </a>
  )
}

export function SteamKeyInstructions() {
  const { t } = useTranslation()

  return (
    <div className="space-y-2">
      <StepList
        items={[
          t('settings.steamStep1'),
          t('settings.steamStep2'),
          t('settings.steamStep3'),
          t('settings.steamStep4'),
        ]}
      />

      <p>
        <DocLink href="https://steamcommunity.com/dev/apikey">
          steamcommunity.com/dev/apikey
        </DocLink>
      </p>

      <p className="text-xs text-muted-foreground">{t('settings.steamNote')}</p>
    </div>
  )
}

export function GoogleOAuthInstructions({ redirectUri }: { redirectUri: string }) {
  const { t } = useTranslation()

  return (
    <div className="space-y-2">
      <StepList
        items={[
          t('settings.googleStep1'),
          t('settings.googleStep2'),
          t('settings.googleStep3'),
        ]}
      />

      <div className="space-y-1">
        <p className="text-xs font-medium">{t('settings.googleRedirectLabel')}</p>
        <code className="block overflow-x-auto rounded bg-background px-2 py-1 text-xs">
          {redirectUri}
        </code>
      </div>

      <p>
        <DocLink href="https://console.cloud.google.com/apis/credentials">
          console.cloud.google.com
        </DocLink>
      </p>

      <p className="text-xs text-muted-foreground">
        <Trans i18nKey="settings.googleNote" />
      </p>
    </div>
  )
}

export function MailerInstructions() {
  const { t } = useTranslation()

  return (
    <div className="space-y-2">
      <p>{t('settings.mailerIntro')}</p>
      <p className="text-xs text-muted-foreground">{t('settings.mailerNote')}</p>
    </div>
  )
}

/**
 * Where the three Discord values come from.
 *
 * All three sit on the same page of the developer portal, but under
 * different headings, and the interaction URL has to go *back* into
 * Discord — which is the step people miss, because it is the only one
 * that does not consist of copying something out.
 */
export function DiscordInstructions({
  interactionUrl,
  reachable,
}: {
  interactionUrl: string
  reachable: boolean
}) {
  const { t } = useTranslation()

  return (
    <div className="space-y-2">
      {/* Stated first, because everything below is pointless until it
          is true: Discord calls the panel, so an address that resolves
          only on this machine can never be reached. */}
      {!reachable && (
        <p className="text-amber-600 dark:text-amber-400">{t('settings.discordLocalUrl')}</p>
      )}

      <StepList
        items={[
          t('settings.discordStep1'),
          t('settings.discordStep2'),
          t('settings.discordStep3'),
          t('settings.discordStep4'),
        ]}
      />

      <p>
        <DocLink href="https://discord.com/developers/applications">
          discord.com/developers/applications
        </DocLink>
      </p>

      <div className="space-y-1">
        <p className="text-xs text-muted-foreground">{t('settings.discordInteractionUrl')}</p>
        <Copyable value={interactionUrl} />
      </div>

      <p className="text-xs text-muted-foreground">{t('settings.discordNote')}</p>
    </div>
  )
}
