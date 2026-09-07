import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  reasonFor,
  saveDiscordCommand,
  type DiscordCommandSetting,
  type DiscordSetup,
} from './discord'
import { RolePicker } from './role-picker'

/**
 * Which Discord roles may run which command.
 *
 * Two things are shown per row and both matter: the roles the operator
 * allowed, and **what the same act costs in the panel**. A command is
 * never a shortcut past a permission — a member needs the role *and*
 * the panel gates the act — and showing the second half stops anybody
 * thinking a role here is the whole story.
 *
 * A command with no role is called out rather than left looking
 * configured: nobody can run it, and that is easy to do by accident.
 */
export function CommandList({ serverId, setup }: { serverId: string; setup: DiscordSetup }) {
  const { t } = useTranslation()

  const grouped = new Map<string, DiscordCommandSetting[]>()

  for (const command of setup.commands) {
    grouped.set(command.command, [...(grouped.get(command.command) ?? []), command])
  }

  return (
    <div className="mt-4 space-y-4">
      <p className="text-muted-foreground text-sm">{t('discord.commandList.hint')}</p>

      {/* Said here rather than left to be discovered: on a fresh guild
          there are no roles at all, and "assigned to nobody" reads as
          "nothing works" when in fact the owner can use everything. */}
      <p className="text-muted-foreground text-sm">
        {t('discord.commandList.adminsAlwaysAllowed')}
      </p>

      {[...grouped].map(([name, commands]) => (
        <Card key={name}>
          <CardHeader>
            <CardTitle className="font-mono text-base">/{name}</CardTitle>
            <CardDescription>{commands.length}</CardDescription>
          </CardHeader>
          <CardContent className="p-0">
            {commands.map((command) => (
              <CommandRow
                key={command.name}
                serverId={serverId}
                command={command}
                disabled={setup.guildId === ''}
              />
            ))}
          </CardContent>
        </Card>
      ))}
    </div>
  )
}

function CommandRow({
  serverId,
  command,
  disabled,
}: {
  serverId: string
  command: DiscordCommandSetting
  disabled: boolean
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  // Saved as it is chosen rather than behind a button: picking a role
  // from a list is already a deliberate act, and 21 rows each with
  // their own unsaved state is a page nobody can keep track of.
  const save = useMutation({
    mutationFn: (roleIds: string[]) => saveDiscordCommand(serverId, command.name, roleIds),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['discord', serverId] })
    },
    onError: (error) => toast.error(t('discord.saveFailed'), { description: reasonFor(error, t) }),
  })

  return (
    <div className="grid gap-2 border-b border-border/60 px-4 py-3 last:border-b-0 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:gap-4 sm:px-6">
      <div className="min-w-0">
        <p className="font-mono text-sm">
          /{command.command} {command.subcommand}
        </p>
        <p className="text-muted-foreground text-xs">
          {command.permission === null
            ? t('discord.verdict.unknownCommand')
            : t('discord.commandList.permission', {
                permission: t(`roles.permissions.${command.permission}.title`, {
                  defaultValue: command.permission,
                }),
              })}
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        {command.roleIds.length === 0 && (
          <Badge variant="outline" className="text-amber-600 dark:text-amber-400">
            {t('discord.commandList.noRoles')}
          </Badge>
        )}

        <RolePicker
          serverId={serverId}
          selected={command.roleIds}
          disabled={disabled || save.isPending}
          onChange={(roleIds) => save.mutate(roleIds)}
        />
      </div>
    </div>
  )
}
