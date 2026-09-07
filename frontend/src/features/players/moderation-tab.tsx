import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { MapPin, PackagePlus, Shield } from 'lucide-react'

import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { SectionMark } from '@/components/layout/section-mark'
import { TeleportDialog } from './teleport-dialog'
import { ACCESS_LEVELS, type AccessLevel, type Player, type TeleportDestination } from './players'

/**
 * What can be done to a player, as cards rather than a row menu.
 *
 * These lived behind a `…` in the table, which is the right shape for a
 * list of thirty rows and the wrong one once a player is already open:
 * hunting back to the row to change their access level is the modal
 * problem again.
 *
 * Items and vehicles link out to their own pickers rather than being
 * embedded — those pages exist, are far better at it, and carry the
 * chosen player through.
 */
export function ModerationTab({
  serverId,
  player,
  players,
  pending,
  onAccessLevel,
  onTeleport,
}: {
  serverId: string
  player: Player
  players: Player[]
  pending: boolean
  onAccessLevel: (level: AccessLevel) => void
  onTeleport: (destination: TeleportDestination) => void
}) {
  const { t } = useTranslation()
  const [teleporting, setTeleporting] = useState(false)

  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="space-y-2 rounded-md border p-3">
          <div className="flex items-center gap-2">
            <Shield aria-hidden className="size-4 text-muted-foreground" />
            <Label htmlFor="access-level" className="text-sm font-medium">
              {t('players.accessLevel')}
            </Label>
          </div>

          <Select
            value={player.accessLevel ?? 'none'}
            disabled={pending}
            onValueChange={(level) => onAccessLevel(level as AccessLevel)}
          >
            <SelectTrigger id="access-level" className="w-full">
              <SelectValue />
            </SelectTrigger>

            <SelectContent>
              {ACCESS_LEVELS.map((level) => (
                <SelectItem key={level} value={level}>
                  {t(`players.level.${level}`)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <p className="text-xs text-muted-foreground">{t('players.accessLevelHint')}</p>
        </div>

        <div className="space-y-2 rounded-md border p-3">
          <div className="flex items-center gap-2">
            <MapPin aria-hidden className="size-4 text-muted-foreground" />
            <span className="text-sm font-medium">{t('players.teleport')}</span>
          </div>

          <Button
            variant="outline"
            className="w-full"
            disabled={!player.online || pending}
            onClick={() => setTeleporting(true)}
          >
            {t('players.chooseDestination')}
          </Button>

          {/* Disabled with a reason beats vanished. */}
          <p className="text-xs text-muted-foreground">
            {player.online ? t('players.teleportHint') : t('players.needsOnline')}
          </p>
        </div>
      </div>

      <section className="space-y-2">
        <SectionMark label={t('players.giveThings')} />

        <div className="grid gap-3 sm:grid-cols-2">
          <PickerLink
            to={`/servers/${serverId}/items?player=${encodeURIComponent(player.username)}`}
            icon={PackagePlus}
            title={t('players.giveItems')}
            description={t('players.giveItemsHint', { player: player.username })}
            disabled={!player.online}
          />

          <PickerLink
            to={`/servers/${serverId}/vehicles?player=${encodeURIComponent(player.username)}`}
            icon={MapPin}
            title={t('players.spawnVehicles')}
            description={t('players.spawnVehiclesHint', { player: player.username })}
            disabled={!player.online}
          />
        </div>
      </section>

      {teleporting && (
        <TeleportDialog
          player={player}
          players={players}
          open
          pending={pending}
          onOpenChange={(open) => !open && setTeleporting(false)}
          onConfirm={(destination) => {
            onTeleport(destination)
            setTeleporting(false)
          }}
        />
      )}
    </div>
  )
}

function PickerLink({
  to,
  icon: Icon,
  title,
  description,
  disabled,
}: {
  to: string
  icon: typeof MapPin
  title: string
  description: string
  disabled: boolean
}) {
  const { t } = useTranslation()

  const body = (
    <>
      <div className="rounded-md bg-muted p-2">
        <Icon aria-hidden className="size-4 text-muted-foreground" />
      </div>

      <div className="min-w-0">
        <p className="text-sm font-medium">{title}</p>
        <p className="text-xs text-muted-foreground">
          {disabled ? t('players.needsOnline') : description}
        </p>
      </div>
    </>
  )

  if (disabled) {
    return (
      <div
        aria-disabled
        className="flex items-start gap-3 rounded-md border p-3 opacity-50"
      >
        {body}
      </div>
    )
  }

  return (
    <Link
      to={to}
      className={cn(
        'flex items-start gap-3 rounded-md border p-3 transition-colors',
        'hover:border-primary/40 hover:bg-muted/50',
      )}
    >
      {body}
    </Link>
  )
}
