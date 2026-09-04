import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { MapPin, Users } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { Player } from './players'

/**
 * Moves one player to another.
 *
 * RCON has no command that puts a named player at coordinates:
 * "teleportto" moves whoever typed it, and RCON has nobody. Only
 * player-to-player is offered here, and honestly so.
 */
export function TeleportDialog({
  player,
  players,
  open,
  pending,
  onOpenChange,
  onConfirm,
}: {
  player: Player
  players: Player[]
  open: boolean
  pending: boolean
  onOpenChange: (open: boolean) => void
  onConfirm: (target: string) => void
}) {
  const { t } = useTranslation()
  const [target, setTarget] = useState<string | null>(null)

  const others = players.filter((entry) => entry.online && entry.username !== player.username)

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('players.teleportTitle', { username: player.username })}</DialogTitle>
          <DialogDescription>{t('players.teleportDescription')}</DialogDescription>
        </DialogHeader>

        {others.length === 0 ? (
          <p className="flex items-start gap-2 rounded-md border p-3 text-sm text-muted-foreground">
            <Users className="mt-0.5 size-4 shrink-0" />
            {t('players.teleportNoOthers')}
          </p>
        ) : (
          <div className="space-y-2">
            <Label htmlFor="teleport-target">{t('players.teleportTarget')}</Label>

            <Select value={target ?? undefined} onValueChange={setTarget}>
              <SelectTrigger id="teleport-target">
                <SelectValue placeholder={t('players.teleportChoose')} />
              </SelectTrigger>
              <SelectContent>
                {others.map((entry) => (
                  <SelectItem key={entry.username} value={entry.username}>
                    {entry.username}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}

        <p className="rounded-md bg-muted p-3 text-xs text-muted-foreground">
          {t('players.teleportCoordinatesNote')}
        </p>

        <DialogFooter>
          <Button variant="ghost" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button
            disabled={target === null || pending}
            onClick={() => target !== null && onConfirm(target)}
          >
            <MapPin className="size-4" />
            {pending ? t('common.loading') : t('players.teleport')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
