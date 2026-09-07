import { useState } from 'react'
import { useParams, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { CircleAlert, Link2Off, TriangleAlert, Upload } from 'lucide-react'

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  activeEventCount,
  getDiscordSetup,
  reasonFor,
  registerDiscordCommands,
  setupGaps,
  ungrantedCommands,
  updateDiscordSetup,
  type DiscordSetup,
} from './discord'
import { EventList } from './event-list'
import { CommandList } from './command-list'
import { ChatSettings } from './chat-settings'

const TABS = ['general', 'events', 'commands', 'chat'] as const

/**
 * Setting up the Discord bot for one server.
 *
 * The page opens on what is missing rather than on a form: without a
 * token, an application id, a public key and a guild, nothing here can
 * work, and each of those is fixed in a different place. Saying which
 * one is absent is the difference between a page somebody can act on
 * and one they can only stare at.
 */
export function DiscordPage() {
  const { id = '' } = useParams()
  const { t } = useTranslation()
  const [search, setSearch] = useSearchParams()

  const tab = TABS.find((each) => each === search.get('tab')) ?? 'general'

  const { data, isPending } = useQuery({
    queryKey: ['discord', id],
    queryFn: () => getDiscordSetup(id),
    enabled: id !== '',
    retry: false,
  })

  if (isPending || data === undefined) {
    return (
      <div className="min-h-full space-y-4 p-4 pb-4 sm:p-6">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }

  const gaps = setupGaps(data)
  const active = activeEventCount(data.events)
  const ungranted = ungrantedCommands(data.commands)

  return (
    <div className="min-h-full space-y-6 p-4 pb-4 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">{t('discord.title')}</h1>
          <p className="text-muted-foreground text-sm">{t('discord.subtitle')}</p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Badge variant={active > 0 ? 'default' : 'secondary'}>
            {active > 0 ? t('discord.events.activeCount', { count: active }) : t('discord.events.noneActive')}
          </Badge>

          {ungranted.length > 0 && (
            <Badge variant="outline" className="gap-1 text-amber-600 dark:text-amber-400">
              <CircleAlert className="size-3" aria-hidden />
              {t('discord.commandList.ungranted', { count: ungranted.length })}
            </Badge>
          )}
        </div>
      </div>

      {gaps.length > 0 && (
        <Alert variant="default" className="border-amber-500/40">
          <TriangleAlert className="size-4" aria-hidden />
          <AlertTitle>{t('discord.notReady')}</AlertTitle>
          <AlertDescription>
            <ul className="list-inside list-disc space-y-1">
              {gaps.map((gap) => (
                <li key={gap}>{t(`discord.gaps.${gap}`)}</li>
              ))}
            </ul>
          </AlertDescription>
        </Alert>
      )}

      <Tabs value={tab} onValueChange={(next) => setSearch({ tab: next }, { replace: true })}>
        <TabsList>
          {TABS.map((each) => (
            <TabsTrigger key={each} value={each}>
              {t(`discord.tabs.${each}`)}
            </TabsTrigger>
          ))}
        </TabsList>

        <TabsContent value="general">
          <Connection serverId={id} setup={data} />
        </TabsContent>

        <TabsContent value="events">
          <EventList serverId={id} setup={data} />
        </TabsContent>

        <TabsContent value="commands">
          <CommandList serverId={id} setup={data} />
        </TabsContent>

        <TabsContent value="chat">
          <ChatSettings serverId={id} setup={data} />
        </TabsContent>
      </Tabs>
    </div>
  )
}

function Connection({ serverId, setup }: { serverId: string; setup: DiscordSetup }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  // Held against the value it was started from, so a refetch cannot
  // overwrite what somebody is typing.
  const [edit, setEdit] = useState<{ from: string; value: string } | null>(null)
  const guildId = edit !== null && edit.from === setup.guildId ? edit.value : setup.guildId

  const save = useMutation({
    mutationFn: (changes: Parameters<typeof updateDiscordSetup>[1]) =>
      updateDiscordSetup(serverId, changes),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['discord', serverId] })
      setEdit(null)
      toast.success(t('discord.saved'))
    },
    onError: (error) => toast.error(t('discord.saveFailed'), { description: reasonFor(error, t) }),
  })

  const register = useMutation({
    mutationFn: () => registerDiscordCommands(serverId),
    onSuccess: (result) => toast.success(t('discord.registered', { count: result.count })),
    onError: (error) => toast.error(t('discord.saveFailed'), { description: reasonFor(error, t) }),
  })

  return (
    <Card className="mt-4">
      <CardHeader>
        <CardTitle className="text-base">{t('discord.tabs.general')}</CardTitle>
        <CardDescription>{t('discord.guildIdHint')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-6">
        <div className="space-y-2">
          <Label htmlFor="discord-guild">{t('discord.guildId')}</Label>
          <div className="flex flex-wrap gap-2">
            <Input
              id="discord-guild"
              value={guildId}
              inputMode="numeric"
              className="max-w-xs font-mono"
              onChange={(event) => setEdit({ from: setup.guildId, value: event.target.value })}
            />
            <Button
              disabled={save.isPending || guildId === setup.guildId}
              onClick={() => save.mutate({ guildId })}
            >
              {t('discord.save')}
            </Button>

            {setup.guildId !== '' && (
              <Button
                variant="ghost"
                disabled={save.isPending}
                onClick={() => save.mutate({ guildId: '' })}
              >
                <Link2Off className="size-4" aria-hidden />
                {t('discord.unlink')}
              </Button>
            )}
          </div>
          {setup.guildId !== '' && (
            <p className="text-muted-foreground text-xs">{t('discord.unlinkHint')}</p>
          )}
        </div>

        <div className="flex items-center justify-between gap-4">
          <Label htmlFor="discord-commands" className="font-normal">
            {t('discord.commandsEnabled')}
          </Label>
          <Switch
            id="discord-commands"
            checked={setup.commandsEnabled}
            disabled={setup.guildId === '' || save.isPending}
            onCheckedChange={(next) => save.mutate({ commandsEnabled: next })}
          />
        </div>

        <div className="space-y-2 border-t pt-4">
          <Button
            variant="secondary"
            disabled={setup.guildId === '' || !setup.tokenConfigured || register.isPending}
            onClick={() => register.mutate()}
          >
            <Upload className="size-4" aria-hidden />
            {t('discord.registerCommands')}
          </Button>
          <p className="text-muted-foreground text-xs">{t('discord.registerHint')}</p>
        </div>
      </CardContent>
    </Card>
  )
}
