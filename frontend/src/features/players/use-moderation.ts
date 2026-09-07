import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { ApiError } from '@/lib/api'
import {
  banPlayer,
  kickPlayer,
  setAccessLevel,
  teleportPlayer,
  type AccessLevel,
  type Player,
  type TeleportDestination,
} from './players'

export type BanInput = {
  username: string
  reason?: string
  durationMinutes: number | null
  includeIp: boolean
}

/**
 * The four moderation commands, in one place.
 *
 * They used to live inside the table, which meant the dossier beside it
 * could not offer kick and ban without a second implementation — and two
 * places that ban somebody is one too many to keep in step.
 *
 * Every command reports the same way: the panel names what it asked for,
 * the server's own reply goes underneath. A verdict from the server beats
 * "the call did not throw".
 */
export function useModeration(serverId: string) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['players', serverId] })

  const report = (successKey: string) => ({
    onSuccess: async (result: { reply: string }) => {
      await invalidate()
      toast.success(t(successKey), {
        description: result.reply === '' ? undefined : result.reply,
      })
    },
    onError: (error: unknown) => {
      const detail =
        error instanceof ApiError && typeof error.payload === 'object' && error.payload !== null
          ? ((error.payload as { detail?: string }).detail ?? '')
          : ''

      toast.error(t('players.commandFailed'), { description: detail || undefined })
    },
  })

  const kick = useMutation({
    mutationFn: (player: Player) => kickPlayer(serverId, player.username),
    ...report('players.kicked'),
  })

  const ban = useMutation({
    mutationFn: (input: BanInput) => banPlayer(serverId, input.username, input),
    ...report('players.banned'),
  })

  const changeLevel = useMutation({
    mutationFn: (input: { username: string; level: AccessLevel }) =>
      setAccessLevel(serverId, input.username, input.level),
    ...report('players.accessLevelChanged'),
  })

  const teleport = useMutation({
    mutationFn: (input: { username: string; destination: TeleportDestination }) =>
      teleportPlayer(serverId, input.username, input.destination),
    ...report('players.teleported'),
  })

  return {
    kick,
    ban,
    changeLevel,
    teleport,
    pending: kick.isPending || ban.isPending || changeLevel.isPending || teleport.isPending,
  }
}
