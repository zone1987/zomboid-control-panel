import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { GraduationCap } from 'lucide-react'

import { ApiError, errorField } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { grantExperience, MAX_XP, type Player } from './players'

/** Sensible steps, so the common case is one click rather than typing. */
const AMOUNTS = [100, 500, 2000, 10000] as const

/**
 * Experience in one skill.
 *
 * **The skills come from the player, not from a table here.** The bridge
 * already reports what this character has and at what level, so a
 * chooser built from that offers exactly the skills that exist on this
 * server — mods included — and cannot drift from the game's own list. A
 * hard-coded set of 47 perk names would have needed maintaining and
 * would still be wrong on a modded server.
 *
 * The multiplier switch is the honest part: on a server running an XP
 * multiplier, "500" means different things with and without it, and the
 * command takes the choice as an argument.
 */
export function ExperienceCard({
  serverId,
  player,
}: {
  serverId: string
  player: Player
}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const skills = Object.keys(player.skills ?? {}).sort()

  const [perk, setPerk] = useState<string | null>(null)
  const [amount, setAmount] = useState(500)
  const [withMultiplier, setWithMultiplier] = useState(false)

  const grant = useMutation({
    mutationFn: () => grantExperience(serverId, player.username, perk as string, amount, withMultiplier),
    onSuccess: () => {
      toast.success(t('players.experienceGranted', { perk, amount }))
      void queryClient.invalidateQueries({ queryKey: ['players', serverId] })
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? (errorField(error, 'detail') ?? t('players.experienceFailed'))
          : t('errors.generic'),
      ),
  })

  if (skills.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        {player.online ? t('players.noSkillsReported') : t('players.skillsNeedOnline')}
      </p>
    )
  }

  const ready = perk !== null && amount >= 1 && amount <= MAX_XP

  return (
    <div className="space-y-3">
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="xp-perk">{t('players.skill')}</Label>

          <Select value={perk ?? undefined} onValueChange={setPerk}>
            <SelectTrigger id="xp-perk" className="w-full">
              <SelectValue placeholder={t('players.chooseSkill')} />
            </SelectTrigger>

            <SelectContent>
              {skills.map((name) => (
                <SelectItem key={name} value={name}>
                  {/* The level as well, so "give them woodwork" is aimed
                      rather than guessed. */}
                  {name}{' '}
                  <span className="text-muted-foreground">
                    {t('players.atLevel', { level: player.skills?.[name] ?? 0 })}
                  </span>
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="xp-amount">{t('players.experience')}</Label>

          <div className="flex items-center gap-2">
            <Input
              id="xp-amount"
              inputMode="numeric"
              className="w-24 text-center font-mono tabular-nums"
              value={String(amount)}
              onChange={(event) => {
                const typed = Number.parseInt(event.target.value, 10)

                setAmount(Number.isNaN(typed) ? 1 : Math.min(MAX_XP, Math.max(1, typed)))
              }}
            />

            {/* Type nothing you could click. */}
            {AMOUNTS.map((step) => (
              <Button
                key={step}
                size="sm"
                variant={amount === step ? 'secondary' : 'ghost'}
                className="px-2 font-mono text-xs"
                onClick={() => setAmount(step)}
              >
                {step}
              </Button>
            ))}
          </div>
        </div>
      </div>

      <div className="flex items-center gap-2">
        <Switch
          id="xp-multiplier"
          checked={withMultiplier}
          onCheckedChange={setWithMultiplier}
        />
        <Label htmlFor="xp-multiplier" className="text-sm font-normal">
          {t('players.useServerMultiplier')}
        </Label>
      </div>

      <Button disabled={!ready || !player.online || grant.isPending} onClick={() => grant.mutate()}>
        <GraduationCap className="size-4" />
        {grant.isPending ? t('common.loading') : t('players.grantExperience')}
      </Button>

      {!player.online && (
        <p className="text-xs text-muted-foreground">{t('players.needsOnline')}</p>
      )}
    </div>
  )
}
