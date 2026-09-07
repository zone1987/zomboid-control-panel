import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { getDiscordChannels } from './discord'

/**
 * Picks a channel by name rather than by id.
 *
 * This is what the bot token buys over a webhook: the channels can be
 * listed, so nobody copies an eighteen-digit id out of Discord and
 * pastes it into the wrong field. Loaded lazily — one request per page,
 * not one per event row.
 */
export function ChannelPicker({
  serverId,
  value,
  disabled,
  onChange,
}: {
  serverId: string
  value: string | null
  disabled?: boolean
  onChange: (channelId: string | null) => void
}) {
  const { t } = useTranslation()

  const { data, isPending } = useQuery({
    queryKey: ['discord-channels', serverId],
    queryFn: () => getDiscordChannels(serverId),
    enabled: serverId !== '' && disabled !== true,
    retry: false,
    // The channel list changes rarely and every row asks for it.
    staleTime: 300_000,
  })

  const channels = data?.channels ?? []

  // A channel the bot can no longer see must stay selected and visible:
  // silently dropping it would look like the operator never chose one.
  const missing = value !== null && !channels.some((channel) => channel.id === value)

  return (
    <Select
      value={value ?? 'none'}
      disabled={disabled === true}
      onValueChange={(next) => onChange(next === 'none' ? null : next)}
    >
      <SelectTrigger className="w-full sm:w-72" aria-label={t('discord.channel')}>
        <SelectValue placeholder={t('discord.chooseChannel')} />
      </SelectTrigger>

      <SelectContent>
        <SelectItem value="none">{t('discord.noChannel')}</SelectItem>

        {missing && <SelectItem value={value}>#{value}</SelectItem>}

        {isPending && channels.length === 0 && (
          <SelectItem value="loading" disabled>
            {t('common.loading')}
          </SelectItem>
        )}

        {channels.map((channel) => (
          <SelectItem key={channel.id} value={channel.id}>
            #{channel.name}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
