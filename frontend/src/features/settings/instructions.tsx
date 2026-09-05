import { Trans, useTranslation } from 'react-i18next'
import { ExternalLink } from 'lucide-react'

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

export function HetznerInstructions() {
  const { t } = useTranslation()

  return (
    <div className="space-y-2">
      <StepList
        items={[
          t('settings.s3Step1'),
          t('settings.s3Step2'),
          t('settings.s3Step3'),
          t('settings.s3Step4'),
        ]}
      />

      <p>
        <DocLink href="https://console.hetzner.cloud/">console.hetzner.cloud</DocLink>
      </p>

      <p className="text-xs text-muted-foreground">{t('settings.s3Note')}</p>
    </div>
  )
}
