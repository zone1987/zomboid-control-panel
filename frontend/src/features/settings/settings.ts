import { apiFetch } from '@/lib/api'

export const SETTING_KEYS = {
  steamApiKey: 'steam.api_key',
  googleClientId: 'google.client_id',
  googleClientSecret: 'google.client_secret',
  mailerDsn: 'mailer.dsn',
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
