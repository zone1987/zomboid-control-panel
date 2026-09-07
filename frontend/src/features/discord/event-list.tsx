import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Send } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  reasonFor,
  saveDiscordEvent,
  testDiscordEvent,
  type DiscordEventSetting,
  type DiscordSetup,
} from './discord'
import { ChannelPicker } from './channel-picker'

/**
 * Which events are announced, where, and in what words.
 *
 * Split into server events and admin actions, because the second group
 * is about what individual people did — and putting that in front of a
 * community is a decision, not a default. All of them start off.
 */
export function EventList({ serverId, setup }: { serverId: string; setup: DiscordSetup }) {
  const { t } = useTranslation()

  const server = setup.events.filter((event) => !event.adminAction)
  const admin = setup.events.filter((event) => event.adminAction)

  return (
    <div className="mt-4 space-y-4">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t('discord.events.serverEvents')}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-0 p-0">
          {server.map((event) => (
            <EventRow key={event.type} serverId={serverId} event={event} setup={setup} />
          ))}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t('discord.events.adminActions')}</CardTitle>
          <CardDescription>{t('discord.events.adminHint')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-0 p-0">
          {admin.map((event) => (
            <EventRow key={event.type} serverId={serverId} event={event} setup={setup} />
          ))}
        </CardContent>
      </Card>
    </div>
  )
}

function EventRow({
  serverId,
  event,
  setup,
}: {
  serverId: string
  event: DiscordEventSetting
  setup: DiscordSetup
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const [edit, setEdit] = useState<{ from: string | null; value: string } | null>(null)
  const template =
    edit !== null && edit.from === event.template ? edit.value : (event.template ?? '')

  const save = useMutation({
    mutationFn: (changes: Parameters<typeof saveDiscordEvent>[2]) =>
      saveDiscordEvent(serverId, event.type, changes),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: ['discord', serverId] })
      setEdit(null)

      // A typo in a placeholder is a warning rather than a refusal: the
      // token stays visible in the message, which harms nothing and is
      // how it gets noticed.
      if (result.unknownTokens.length > 0) {
        toast.warning(t('discord.events.unknownTokens', { tokens: result.unknownTokens.join(', ') }))

        return
      }

      toast.success(t('discord.saved'))
    },
    onError: (error) => toast.error(t('discord.saveFailed'), { description: reasonFor(error, t) }),
  })

  const test = useMutation({
    mutationFn: () =>
      testDiscordEvent(serverId, event.type, {
        channelId: event.channelId,
        template: template === '' ? null : template,
      }),
    onSuccess: (result) => toast.success(t('discord.events.tested'), { description: result.content }),
    onError: (error) => toast.error(t('discord.saveFailed'), { description: reasonFor(error, t) }),
  })

  const label = t(`discord.eventNames.${event.type}`, { defaultValue: event.type })

  return (
    <div className="space-y-3 border-b border-border/60 px-4 py-3 last:border-b-0 sm:px-6">
      <div className="flex flex-wrap items-center gap-3">
        <Switch
          checked={event.enabled}
          disabled={setup.guildId === '' || save.isPending}
          aria-label={label}
          onCheckedChange={(next) => save.mutate({ enabled: next })}
        />

        <div className="min-w-0 flex-1">
          <p className="font-medium">{label}</p>
          <p className="text-muted-foreground/70 font-mono text-xs">{event.type}</p>
        </div>

        <ChannelPicker
          serverId={serverId}
          value={event.channelId}
          disabled={setup.guildId === ''}
          onChange={(channelId) => save.mutate({ channelId })}
        />
      </div>

      {/* Only for an event somebody switched on: 22 textareas on a page
          nobody has configured yet is noise, not a feature. */}
      {event.enabled && (
        <div className="space-y-2 pl-0 sm:pl-14">
          <Textarea
            value={template}
            rows={2}
            placeholder={event.defaultTemplate ?? ''}
            aria-label={t('discord.events.template')}
            className="font-mono text-sm"
            onChange={(change) => setEdit({ from: event.template, value: change.target.value })}
          />

          <p className="text-muted-foreground text-xs">
            {t('discord.events.templateHint', {
              tokens: event.tokens.map((token) => `{${token}}`).join(' '),
            })}
          </p>

          <div className="flex flex-wrap items-center gap-2">
            <Button
              size="sm"
              disabled={save.isPending || template === (event.template ?? '')}
              onClick={() => save.mutate({ template: template === '' ? null : template })}
            >
              {t('discord.save')}
            </Button>

            <Button
              size="sm"
              variant="ghost"
              disabled={test.isPending || event.channelId === null}
              onClick={() => test.mutate()}
            >
              <Send className="size-3.5" aria-hidden />
              {t('discord.events.test')}
            </Button>

            {event.template === null && (
              <span className="text-muted-foreground text-xs">
                {t('discord.events.usesDefault')}
              </span>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
