import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Eye, Ghost, MicOff, Shield, type LucideIcon } from 'lucide-react'

import { cn } from '@/lib/utils'
import { ApiError, errorField } from '@/lib/api'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { ABILITIES, setAbility, type Ability, type AbilityState } from './players'

/** An icon per ability, so a row is found by sight. */
const ICONS: Record<Ability, LucideIcon> = {
  god: Shield,
  invisible: Eye,
  noclip: Ghost,
  voiceBan: MicOff,
}

/**
 * God mode, invisibility, noclip and the voice ban.
 *
 * **Three states, not two.** The server keeps none of these anywhere the
 * panel can read back, so a switch showing "off" would be claiming
 * something nobody checked — and two admins fighting over one flag is
 * exactly what that produces. Each row therefore starts as *unknown* and
 * only claims a state once this panel set it.
 *
 * Two buttons rather than a switch, for the same reason: a switch has no
 * third position, and its position would be a lie until pressed.
 */
export function AbilityRows({
  serverId,
  username,
  online,
}: {
  serverId: string
  username: string
  online: boolean
}) {
  const { t } = useTranslation()

  // What this panel has set, this session. Deliberately not persisted:
  // it is knowledge about a running server, and a restart invalidates it.
  const [known, setKnown] = useState<Partial<Record<Ability, AbilityState>>>({})

  return (
    <div className="space-y-3">
      {ABILITIES.map((ability) => (
        <AbilityRow
          key={ability}
          ability={ability}
          state={known[ability] ?? 'unknown'}
          serverId={serverId}
          username={username}
          online={online}
          onSet={(state) => setKnown((previous) => ({ ...previous, [ability]: state }))}
        />
      ))}

      {/* The one that does not survive a reconnect, said plainly rather
          than shown as a flag that quietly stops being true. */}
      <p className="border-t pt-2 text-xs text-muted-foreground">
        {t('players.voiceBanIsTemporary')}
      </p>
    </div>
  )
}

function AbilityRow({
  ability,
  state,
  serverId,
  username,
  online,
  onSet,
}: {
  ability: Ability
  state: AbilityState
  serverId: string
  username: string
  online: boolean
  onSet: (state: AbilityState) => void
}) {
  const { t } = useTranslation()

  const Icon = ICONS[ability]

  const set = useMutation({
    mutationFn: (on: boolean) => setAbility(serverId, username, ability, on),
    onSuccess: (_result, on) => {
      onSet(on ? 'on' : 'off')
      toast.success(t('players.abilitySet', { ability: t(`players.abilities.${ability}`) }))
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? (errorField(error, 'detail') ?? t('players.abilityFailed'))
          : t('errors.generic'),
      ),
  })

  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
      <span className="flex items-center gap-2 text-sm font-medium">
        <Icon aria-hidden className="size-4 text-muted-foreground" />
        {t(`players.abilities.${ability}`)}
      </span>

      <Badge
        variant={state === 'on' ? 'default' : 'outline'}
        className={cn(state === 'unknown' && 'text-muted-foreground')}
      >
        {t(`players.abilityStates.${state}`)}
      </Badge>

      <div className="ml-auto flex gap-1">
        <Button
          size="sm"
          variant={state === 'on' ? 'default' : 'outline'}
          disabled={!online || set.isPending}
          onClick={() => set.mutate(true)}
        >
          {t('players.abilityOn')}
        </Button>

        <Button
          size="sm"
          variant={state === 'off' ? 'secondary' : 'outline'}
          disabled={!online || set.isPending}
          onClick={() => set.mutate(false)}
        >
          {t('players.abilityOff')}
        </Button>
      </div>
    </div>
  )
}
