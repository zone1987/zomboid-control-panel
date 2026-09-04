import { apiFetch } from '@/lib/api'

export const SETTING_KEYS = {
  steamApiKey: 'steam.api_key',
  googleClientId: 'google.client_id',
  googleClientSecret: 'google.client_secret',
  mailHost: 'mailer.host',
  mailPort: 'mailer.port',
  mailUsername: 'mailer.username',
  mailPassword: 'mailer.password',
  mailEncryption: 'mailer.encryption',
  mailFromAddress: 'mailer.from_address',
  mailFromName: 'mailer.from_name',
} as const

export type SettingKey = (typeof SETTING_KEYS)[keyof typeof SETTING_KEYS]

export type SettingState = {
  configured: boolean
  fromEnvironment: boolean
  value: string | null
  secret: boolean
}

export type SettingsResponse = {
  items: Record<SettingKey, SettingState>
  googleRedirectUri: string
}

export function listSettings(): Promise<SettingsResponse> {
  return apiFetch<SettingsResponse>('/settings')
}

export function updateSettings(values: Partial<Record<SettingKey, string>>): Promise<SettingsResponse> {
  return apiFetch<SettingsResponse>('/settings', { method: 'PATCH', body: values })
}

export function testSteamKey(): Promise<{ status: string; sample?: string }> {
  return apiFetch<{ status: string; sample?: string }>('/settings/steam/test', {
    method: 'POST',
    body: {},
  })
}

export type DeliverabilityStatus = 'ok' | 'warning' | 'missing'

export type DeliverabilityCheck = {
  id: 'spf' | 'dkim' | 'dmarc'
  status: DeliverabilityStatus
  reason: string
  found: string | null
  recordName: string | null
  suggestedValue: string | null
}

export type DeliverabilityReport = {
  domain: string | null
  senderAddress: string | null
  mailHost: string | null
  verdict: 'good' | 'partial' | 'atRisk' | 'noSender'
  checks: DeliverabilityCheck[]
}

export function checkDeliverability(): Promise<DeliverabilityReport> {
  return apiFetch<DeliverabilityReport>('/settings/mail/deliverability')
}

export function testMail(recipient?: string): Promise<{ status: string; recipient: string }> {
  return apiFetch('/settings/mail/test', { method: 'POST', body: { recipient } })
}

/** Ready-made settings for the providers people actually use. */
export const MAIL_PRESETS = [
  // Hetzner shared hosting: every mailbox uses the same outgoing server,
  // whatever the domain — the username is the full mailbox address.
  { id: 'hetzner', label: 'Hetzner', host: 'mail.your-server.de', port: '587', encryption: 'tls' },
  { id: 'gmail', label: 'Gmail', host: 'smtp.gmail.com', port: '587', encryption: 'tls' },
  { id: 'outlook', label: 'Outlook', host: 'smtp-mail.outlook.com', port: '587', encryption: 'tls' },
  { id: 'gmx', label: 'GMX', host: 'mail.gmx.net', port: '587', encryption: 'tls' },
  { id: 'webde', label: 'WEB.DE', host: 'smtp.web.de', port: '587', encryption: 'tls' },
  { id: 'ionos', label: 'IONOS', host: 'smtp.ionos.de', port: '587', encryption: 'tls' },
  { id: 'strato', label: 'Strato', host: 'smtp.strato.de', port: '587', encryption: 'tls' },
  { id: 'netcup', label: 'netcup', host: 'mail.netcup.net', port: '587', encryption: 'tls' },
  { id: 'mailbox', label: 'mailbox.org', host: 'smtp.mailbox.org', port: '587', encryption: 'tls' },
  { id: 'posteo', label: 'Posteo', host: 'posteo.de', port: '587', encryption: 'tls' },
  { id: 'mailgun', label: 'Mailgun', host: 'smtp.mailgun.org', port: '587', encryption: 'tls' },
  { id: 'sendgrid', label: 'SendGrid', host: 'smtp.sendgrid.net', port: '587', encryption: 'tls' },
  { id: 'brevo', label: 'Brevo', host: 'smtp-relay.brevo.com', port: '587', encryption: 'tls' },
] as const
