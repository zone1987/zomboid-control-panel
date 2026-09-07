import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ShieldCheck } from 'lucide-react'

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { reasonFor, updateDiscordSetup, type ChatScope, type DiscordSetup } from './discord'
import { ChannelPicker } from './channel-picker'

const SCOPES: ChatScope[] = ['general', 'noShouting', 'allPublic']

/**
 * Mirroring chat between the game and Discord.
 *
 * The scope is an allow-list, and the note saying which channels are
 * never mirrored is part of the control rather than documentation
 * elsewhere: faction, safehouse, radio, admin and whisper chat are
 * private in the game, and an operator choosing "all public chat"
 * should be able to see that those are still excluded.
 */
export function ChatSettings({ serverId, setup }: { serverId: string; setup: DiscordSetup }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const save = useMutation({
    mutationFn: (changes: Parameters<typeof updateDiscordSetup>[1]) =>
      updateDiscordSetup(serverId, changes),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['discord', serverId] })
      toast.success(t('discord.saved'))
    },
    onError: (error) => toast.error(t('discord.saveFailed'), { description: reasonFor(error, t) }),
  })

  const linked = setup.guildId !== ''

  return (
    <Card className="mt-4">
      <CardHeader>
        <CardTitle className="text-base">{t('discord.chat.title')}</CardTitle>
        <CardDescription>{t('discord.chat.scopeHint')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-6">
        <div className="space-y-2">
          <Label>{t('discord.chat.toDiscord')}</Label>
          <ChannelPicker
            serverId={serverId}
            value={setup.chatChannelId}
            disabled={!linked}
            onChange={(channelId) => save.mutate({ chatChannelId: channelId })}
          />
        </div>

        <div className="space-y-2">
          <Label htmlFor="chat-scope">{t('discord.chat.scope')}</Label>
          <Select
            value={setup.chatScope}
            disabled={!linked || setup.chatChannelId === null}
            onValueChange={(next) => save.mutate({ chatScope: next as ChatScope })}
          >
            <SelectTrigger id="chat-scope" className="w-full sm:w-72">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {SCOPES.map((scope) => (
                <SelectItem key={scope} value={scope}>
                  {t(`discord.chat.scopes.${scope}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <p className="text-muted-foreground flex items-start gap-2 text-xs">
            <ShieldCheck className="mt-0.5 size-3.5 shrink-0" aria-hidden />
            {t('discord.chat.scopeHint')}
          </p>
        </div>

        {/* Offered but disabled, with the reason: a control that needs
            something the deployment does not have yet is better shown
            as unavailable than hidden. */}
        <div className="space-y-1 border-t pt-4">
          <div className="flex items-center justify-between gap-4">
            <Label htmlFor="chat-into-game" className="font-normal">
              {t('discord.chat.intoGame')}
            </Label>
            <Switch id="chat-into-game" checked={false} disabled />
          </div>
          <p className="text-muted-foreground text-xs">{t('discord.chat.intoGameHint')}</p>
        </div>
      </CardContent>
    </Card>
  )
}
