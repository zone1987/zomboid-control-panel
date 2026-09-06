import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import {
  Ban as BanIcon,
  Biohazard,
  Heart,
  LogOut,
  MapPin,
  MoreHorizontal,
  PackagePlus,
  Shield,
  Users,
} from 'lucide-react'
import { useNavigate } from 'react-router'

import { cn } from '@/lib/utils'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { BanDialog } from './ban-dialog'
import { TeleportDialog } from './teleport-dialog'
import {
  ACCESS_LEVELS,
  banPlayer,
  kickPlayer,
  listPlayers,
  setAccessLevel,
  teleportPlayer,
  type AccessLevel,
  type Player,
  type TeleportDestination,
} from './players'

export function PlayerTable({
  serverId,
  selected,
  onSelect,
}: {
  serverId: string
  /** The username the dossier beside the list is showing, if any. */
  selected?: string | null
  /**
   * Told rather than shown: the dossier lives beside the list so a row
   * can be compared with the one before it, which a dialog cannot do.
   */
  onSelect?: (player: Player) => void
}) {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const [onlineOnly, setOnlineOnly] = useState(false)
  const [banning, setBanning] = useState<Player | null>(null)
  const [teleporting, setTeleporting] = useState<Player | null>(null)

  const navigate = useNavigate()

  const { data, isPending } = useQuery({
    queryKey: ['players', serverId, onlineOnly],
    queryFn: () => listPlayers(serverId, onlineOnly),
    // The bridge writes on every join and leave, so a short interval is
    // mostly about catching movement and health. It carries on in a
    // background tab so a dashboard left open stays current.
    refetchInterval: 3_000,
    refetchIntervalInBackground: true,
    // Showing the previous list while the next arrives avoids a flash of
    // skeleton every five seconds.
    placeholderData: (previous) => previous,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['players', serverId] })

  // Every moderation command reports the same way: the panel names what
  // it asked for, the server's own prose reply goes underneath.
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
    mutationFn: (input: {
      username: string
      reason?: string
      durationMinutes: number | null
      includeIp: boolean
    }) => banPlayer(serverId, input.username, input),
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

  if (isPending) {
    return <Skeleton className="h-64 w-full" />
  }

  const players = data?.items ?? []
  const bridge = data?.bridge

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <Switch id="online-only" checked={onlineOnly} onCheckedChange={setOnlineOnly} />
          <Label htmlFor="online-only" className="font-normal">
            {t('players.onlineOnly')}
          </Label>
        </div>

        <div className="flex items-center gap-3">
          {bridge && (
            <span className="text-xs text-muted-foreground">
              {t('players.bridgeUpdated', {
                time: new Date(bridge.generatedAt).toLocaleTimeString(i18n.language),
              })}
              {bridge.version && ` · ${bridge.version}`}
            </span>
          )}
        </div>
      </div>

      {data?.error && (
        <Alert variant="destructive">
          <AlertTitle>{t('players.bridgeUnavailable')}</AlertTitle>
          <AlertDescription>{t(data.error)}</AlertDescription>
        </Alert>
      )}

      {bridge && bridge.version !== null && bridge.version !== bridge.expectedVersion && (
        <Alert>
          <AlertTitle>{t('players.bridgeOutdated')}</AlertTitle>
          <AlertDescription>
            {t('players.bridgeOutdatedHint', {
              installed: bridge.version,
              expected: bridge.expectedVersion,
            })}
          </AlertDescription>
        </Alert>
      )}

      {bridge?.stale && (
        <Alert>
          <AlertTitle>{t('players.bridgeStale')}</AlertTitle>
          <AlertDescription>{t('players.bridgeStaleHint')}</AlertDescription>
        </Alert>
      )}

      {players.length === 0 ? (
        <Empty>
          <EmptyHeader>
            <EmptyMedia variant="icon">
              <Users />
            </EmptyMedia>
            <EmptyTitle>{t('players.none')}</EmptyTitle>
            <EmptyDescription>{t('players.noneHint')}</EmptyDescription>
          </EmptyHeader>
        </Empty>
      ) : (
        <div className="overflow-x-auto rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{t('players.player')}</TableHead>
                <TableHead>{t('players.condition')}</TableHead>
                <TableHead>{t('players.position')}</TableHead>
                <TableHead>{t('players.accessLevel')}</TableHead>
                <TableHead className="w-12 text-right">{t('common.actions')}</TableHead>
              </TableRow>
            </TableHeader>

            <TableBody>
              {players.map((player) => (
                <TableRow
                  key={player.username}
                  // The chosen row is marked, because the dossier beside
                  // it is showing that player and nothing else says so.
                  className={cn(
                    'cursor-pointer',
                    selected === player.username && 'bg-accent/50',
                  )}
                  onClick={() => onSelect?.(player)}
                >
                  <TableCell>
                    <div className="flex flex-col">
                      <span className="flex items-center gap-2 font-medium">
                        <span
                          aria-hidden
                          className={
                            player.online
                              ? 'size-2 rounded-full bg-emerald-500'
                              : 'size-2 rounded-full bg-muted-foreground/40'
                          }
                        />
                        {player.username}
                      </span>
                      <span className="text-xs text-muted-foreground">
                        {player.online
                          ? t('players.online')
                          : t('players.lastSeen', {
                              time: new Date(player.lastSeenAt).toLocaleString(i18n.language),
                            })}
                      </span>
                    </div>
                  </TableCell>

                  <TableCell>
                    <Condition player={player} />
                  </TableCell>

                  <TableCell className="font-mono text-xs text-muted-foreground">
                    {player.position.x === null
                      ? '—'
                      : `${Math.round(player.position.x)}, ${Math.round(player.position.y ?? 0)}`}
                  </TableCell>

                  <TableCell>
                    <Badge variant={player.accessLevel === 'none' ? 'outline' : 'secondary'}>
                      {t(`players.level.${player.accessLevel ?? 'none'}`)}
                    </Badge>
                  </TableCell>

                  <TableCell className="text-right" onClick={(event) => event.stopPropagation()}>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="size-8">
                          <MoreHorizontal className="size-4" />
                          <span className="sr-only">{t('common.actions')}</span>
                        </Button>
                      </DropdownMenuTrigger>

                      <DropdownMenuContent align="end">
                        <DropdownMenuItem
                          disabled={!player.online || kick.isPending}
                          onSelect={() => kick.mutate(player)}
                        >
                          <LogOut className="size-4" />
                          {t('players.kick')}
                        </DropdownMenuItem>

                        <DropdownMenuItem variant="destructive" onSelect={() => setBanning(player)}>
                          <BanIcon className="size-4" />
                          {t('players.ban')}
                        </DropdownMenuItem>

                        <DropdownMenuSeparator />

                        {/* Both need somebody actually in the world to
                            act on, so neither is offered while offline. */}
                        <DropdownMenuItem
                          disabled={!player.online}
                          onSelect={() =>
                            void navigate(
                              `/servers/${serverId}/items?player=${encodeURIComponent(player.username)}`,
                            )
                          }
                        >
                          <PackagePlus className="size-4" />
                          {t('players.giveItems')}
                        </DropdownMenuItem>

                        <DropdownMenuItem
                          disabled={!player.online}
                          onSelect={() => setTeleporting(player)}
                        >
                          <MapPin className="size-4" />
                          {t('players.teleport')}
                        </DropdownMenuItem>

                        <DropdownMenuSeparator />

                        <DropdownMenuSub>
                          <DropdownMenuSubTrigger>
                            <Shield className="size-4" />
                            {t('players.accessLevel')}
                          </DropdownMenuSubTrigger>

                          <DropdownMenuSubContent>
                            <DropdownMenuLabel>{t('players.accessLevel')}</DropdownMenuLabel>
                            <DropdownMenuRadioGroup
                              value={player.accessLevel ?? 'none'}
                              onValueChange={(level) =>
                                changeLevel.mutate({
                                  username: player.username,
                                  level: level as AccessLevel,
                                })
                              }
                            >
                              {ACCESS_LEVELS.map((level) => (
                                <DropdownMenuRadioItem key={level} value={level}>
                                  {t(`players.level.${level}`)}
                                </DropdownMenuRadioItem>
                              ))}
                            </DropdownMenuRadioGroup>
                          </DropdownMenuSubContent>
                        </DropdownMenuSub>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      {banning && (
        <BanDialog
          username={banning.username}
          open
          pending={ban.isPending}
          onOpenChange={(open) => !open && setBanning(null)}
          onConfirm={(options) => {
            ban.mutate({ username: banning.username, ...options })
            setBanning(null)
          }}
        />
      )}

      {teleporting && (
        <TeleportDialog
          player={teleporting}
          players={players}
          open
          pending={teleport.isPending}
          onOpenChange={(open) => !open && setTeleporting(null)}
          onConfirm={(destination) => {
            teleport.mutate({ username: teleporting.username, destination })
            setTeleporting(null)
          }}
        />
      )}


    </div>
  )
}

function Condition({ player }: { player: Player }) {
  const { t } = useTranslation()

  if (!player.online && player.health === null) {
    return <span className="text-sm text-muted-foreground">—</span>
  }

  return (
    <div className="flex flex-wrap items-center gap-2 text-sm">
      {player.health !== null && (
        <span className="flex items-center gap-1" title={t('players.health')}>
          <Heart
            className={player.health < 0.5 ? 'size-3.5 text-destructive' : 'size-3.5 text-emerald-500'}
          />
          {Math.round(player.health * 100)}%
        </span>
      )}

      {player.infected && (
        <Badge variant="destructive" className="gap-1 text-xs">
          <Biohazard className="size-3" />
          {player.infectionLevel === null
            ? t('players.infected')
            : `${Math.round(player.infectionLevel * 100)}%`}
        </Badge>
      )}

      {player.hoursSurvived !== null && (
        <span className="text-xs text-muted-foreground">
          {t('players.survived', { hours: Math.round(player.hoursSurvived) })}
        </span>
      )}
    </div>
  )
}
