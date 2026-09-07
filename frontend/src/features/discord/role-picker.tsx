import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Check, ChevronsUpDown, Wrench } from 'lucide-react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { getDiscordRoles, roleColour, type DiscordRole } from './discord'

/**
 * Picks Discord roles by name instead of by id.
 *
 * An id is nineteen digits that all look alike, and a mistyped one
 * grants a command to nothing at all — silently, since there is no
 * feedback until somebody tries to use it. The names come from the
 * guild, in Discord's own order and colours, so the operator recognises
 * what they are choosing.
 *
 * A role the panel cannot see any more stays selected and is shown by
 * its id: dropping it would quietly revoke a permission somebody set.
 */
export function RolePicker({
  serverId,
  selected,
  disabled,
  onChange,
}: {
  serverId: string
  selected: string[]
  disabled?: boolean
  onChange: (roleIds: string[]) => void
}) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)

  const { data, isPending } = useQuery({
    queryKey: ['discord-roles', serverId],
    queryFn: () => getDiscordRoles(serverId),
    enabled: serverId !== '' && disabled !== true,
    retry: false,
    // Roles change rarely and every row on the page asks for them.
    staleTime: 300_000,
  })

  const roles = data?.roles ?? []
  const byId = new Map(roles.map((role) => [role.id, role]))
  const unknown = selected.filter((id) => !byId.has(id))

  const toggle = (id: string) =>
    onChange(selected.includes(id) ? selected.filter((each) => each !== id) : [...selected, id])

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          disabled={disabled === true}
          className="h-auto min-h-9 w-full justify-between gap-2 sm:w-72"
          aria-label={t('discord.commandList.roles')}
        >
          <span className="flex flex-wrap items-center gap-1">
            {selected.length === 0 ? (
              <span className="text-muted-foreground">{t('discord.commandList.chooseRoles')}</span>
            ) : (
              <>
                {selected.map((id) => (
                  <RoleChip key={id} id={id} role={byId.get(id)} />
                ))}
              </>
            )}
          </span>
          <ChevronsUpDown className="size-4 shrink-0 opacity-50" aria-hidden />
        </Button>
      </PopoverTrigger>

      <PopoverContent className="w-72 p-1" align="end">
        {isPending && (
          <p className="text-muted-foreground px-2 py-3 text-sm">{t('common.loading')}</p>
        )}

        {!isPending && roles.length === 0 && (
          <p className="text-muted-foreground px-2 py-3 text-sm">
            {t('discord.commandList.noRolesFound')}
          </p>
        )}

        <div className="max-h-72 overflow-y-auto">
          {roles.map((role) => (
            <button
              key={role.id}
              type="button"
              className="hover:bg-muted flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm"
              onClick={() => toggle(role.id)}
            >
              <Check
                className={`size-4 shrink-0 ${selected.includes(role.id) ? '' : 'invisible'}`}
                aria-hidden
              />
              <RoleDot role={role} />
              <span className="min-w-0 flex-1 truncate">{role.name}</span>
              {role.managed && (
                <Wrench
                  className="text-muted-foreground size-3 shrink-0"
                  aria-label={t('discord.commandList.managedRole')}
                />
              )}
            </button>
          ))}

          {/* A role the bot can no longer see, still granting a command.
              Shown so it can be removed on purpose rather than lost. */}
          {unknown.map((id) => (
            <button
              key={id}
              type="button"
              className="hover:bg-muted flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm"
              onClick={() => toggle(id)}
            >
              <Check className="size-4 shrink-0" aria-hidden />
              <span className="text-muted-foreground min-w-0 flex-1 truncate font-mono text-xs">
                {id}
              </span>
            </button>
          ))}
        </div>
      </PopoverContent>
    </Popover>
  )
}

function RoleChip({ id, role }: { id: string; role: DiscordRole | undefined }) {
  if (role === undefined) {
    return (
      <Badge variant="outline" className="font-mono text-xs">
        {id.slice(0, 6)}…
      </Badge>
    )
  }

  const colour = roleColour(role)

  return (
    <Badge variant="secondary" className="gap-1">
      <RoleDot role={role} />
      {role.name}
      {colour === null && null}
    </Badge>
  )
}

/** Discord shows a role by its colour, so the picker does too. */
function RoleDot({ role }: { role: DiscordRole }) {
  const colour = roleColour(role)

  return (
    <span
      className="size-2 shrink-0 rounded-full"
      style={{ backgroundColor: colour ?? 'var(--color-muted-foreground)' }}
      aria-hidden
    />
  )
}
