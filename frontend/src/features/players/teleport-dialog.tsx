import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { MapPin, Users } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { isInsideWorld, LANDMARKS, WORLD_BOUNDS, type Player, type TeleportDestination } from './players'

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
  onConfirm: (destination: TeleportDestination) => void
}) {
  const { t } = useTranslation()
  const [target, setTarget] = useState<string | null>(null)
  const [coordinates, setCoordinates] = useState({ x: '', y: '', z: '0' })
  const [mode, setMode] = useState<'player' | 'coordinates'>('player')

  const others = players.filter((entry) => entry.online && entry.username !== player.username)

  const parsed = {
    x: Number.parseInt(coordinates.x, 10),
    y: Number.parseInt(coordinates.y, 10),
    z: Number.parseInt(coordinates.z === '' ? '0' : coordinates.z, 10),
  }

  const coordinatesValid = isInsideWorld(parsed.x, parsed.y, parsed.z)
  const touched = coordinates.x !== '' || coordinates.y !== ''

  const confirm = () => {
    if (mode === 'player') {
      if (target !== null) {
        onConfirm({ target })
      }

      return
    }

    if (coordinatesValid) {
      onConfirm(parsed)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('players.teleportTitle', { username: player.username })}</DialogTitle>
          <DialogDescription>{t('players.teleportDescription')}</DialogDescription>
        </DialogHeader>

        <Tabs value={mode} onValueChange={(value) => setMode(value as 'player' | 'coordinates')}>
          <TabsList className="w-full">
            <TabsTrigger value="player" className="flex-1">
              {t('players.teleportToPlayer')}
            </TabsTrigger>
            <TabsTrigger value="coordinates" className="flex-1">
              {t('players.teleportToCoordinates')}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="player" className="pt-4">
            {others.length === 0 ? (
              <p className="flex items-start gap-2 rounded-md border p-3 text-sm text-muted-foreground">
                <Users className="mt-0.5 size-4 shrink-0" />
                {t('players.teleportNoOthers')}
              </p>
            ) : (
              <div className="space-y-2">
                <Label htmlFor="teleport-target">{t('players.teleportTarget')}</Label>

                <Select value={target ?? undefined} onValueChange={setTarget}>
                  <SelectTrigger id="teleport-target" className="w-full">
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
          </TabsContent>

          <TabsContent value="coordinates" className="space-y-4 pt-4">
            <div className="grid grid-cols-3 gap-2">
              {(['x', 'y', 'z'] as const).map((axis) => (
                <div key={axis} className="space-y-1.5">
                  <Label htmlFor={`teleport-${axis}`} className="uppercase">
                    {axis}
                  </Label>
                  <Input
                    id={`teleport-${axis}`}
                    type="number"
                    inputMode="numeric"
                    value={coordinates[axis]}
                    min={axis === 'z' ? WORLD_BOUNDS.minZ : WORLD_BOUNDS.min}
                    max={axis === 'z' ? WORLD_BOUNDS.maxZ : WORLD_BOUNDS.max}
                    onChange={(event) =>
                      setCoordinates((previous) => ({ ...previous, [axis]: event.target.value }))
                    }
                  />
                </div>
              ))}
            </div>

            <p className="text-xs text-muted-foreground">
              {touched && !coordinatesValid
                ? t('players.teleportOutsideWorld')
                : t('players.teleportZHint')}
            </p>

            <div className="space-y-1.5">
              <span className="text-xs font-medium text-muted-foreground">
                {t('players.teleportLandmarks')}
              </span>

              <div className="flex flex-wrap gap-1.5">
                {LANDMARKS.map((place) => (
                  <Button
                    key={place.id}
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() =>
                      setCoordinates({ x: String(place.x), y: String(place.y), z: '0' })
                    }
                  >
                    {t(`players.landmarks.${place.id}`)}
                  </Button>
                ))}
              </div>
            </div>
          </TabsContent>
        </Tabs>

        <DialogFooter>
          <Button variant="ghost" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button
            disabled={pending || (mode === 'player' ? target === null : !coordinatesValid)}
            onClick={confirm}
          >
            <MapPin className="size-4" />
            {pending ? t('common.loading') : t('players.teleport')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
