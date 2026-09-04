import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
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
import { BAN_DURATIONS } from './players'

const CUSTOM = 'custom'
const UNITS = { minutes: 1, hours: 60, days: 1440 } as const

type Unit = keyof typeof UNITS

export function BanDialog({
  username,
  open,
  pending,
  onOpenChange,
  onConfirm,
}: {
  username: string
  open: boolean
  pending: boolean
  onOpenChange: (open: boolean) => void
  onConfirm: (options: { reason?: string; durationMinutes: number | null; includeIp: boolean }) => void
}) {
  const { t } = useTranslation()
  const [preset, setPreset] = useState<string>('1d')
  const [amount, setAmount] = useState('3')
  const [unit, setUnit] = useState<Unit>('days')
  const [reason, setReason] = useState('')
  const [includeIp, setIncludeIp] = useState(false)

  const customMinutes = Math.round(Number(amount) * UNITS[unit])
  const customIsValid = Number.isFinite(customMinutes) && customMinutes >= 1 && customMinutes <= 525_600

  const durationMinutes =
    preset === CUSTOM
      ? customIsValid
        ? customMinutes
        : null
      : (BAN_DURATIONS.find((option) => option.id === preset)?.minutes ?? null)

  const blocked = preset === CUSTOM && !customIsValid

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('players.banTitle', { username })}</DialogTitle>
          <DialogDescription>{t('players.banDescription')}</DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="ban-duration">{t('players.banDuration')}</Label>

            <Select value={preset} onValueChange={setPreset}>
              <SelectTrigger id="ban-duration">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {BAN_DURATIONS.map((option) => (
                  <SelectItem key={option.id} value={option.id}>
                    {t(`players.duration.${option.id}`)}
                  </SelectItem>
                ))}
                <SelectItem value={CUSTOM}>{t('players.duration.custom')}</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {preset === CUSTOM && (
            <div className="space-y-2">
              <Label htmlFor="ban-amount">{t('players.banCustomDuration')}</Label>

              <div className="flex gap-2">
                <Input
                  id="ban-amount"
                  type="number"
                  min={1}
                  className="w-28"
                  value={amount}
                  onChange={(event) => setAmount(event.target.value)}
                />

                <Select value={unit} onValueChange={(value) => setUnit(value as Unit)}>
                  <SelectTrigger className="flex-1">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="minutes">{t('players.unit.minutes')}</SelectItem>
                    <SelectItem value="hours">{t('players.unit.hours')}</SelectItem>
                    <SelectItem value="days">{t('players.unit.days')}</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              {!customIsValid && <p className="text-sm text-destructive">{t('players.banDurationRange')}</p>}
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="ban-reason">{t('players.reason')}</Label>
            <Input
              id="ban-reason"
              value={reason}
              placeholder={t('players.reasonPlaceholder')}
              onChange={(event) => setReason(event.target.value)}
            />
            <p className="text-xs text-muted-foreground">{t('players.reasonHint')}</p>
          </div>

          <div className="flex items-start gap-2">
            <Checkbox
              id="ban-ip"
              checked={includeIp}
              onCheckedChange={(checked) => setIncludeIp(checked === true)}
            />
            <div className="grid gap-0.5 leading-none">
              <Label htmlFor="ban-ip" className="font-normal">
                {t('players.banIp')}
              </Label>
              <p className="text-xs text-muted-foreground">{t('players.banIpHint')}</p>
            </div>
          </div>

          <p className="rounded-md bg-muted p-3 text-xs text-muted-foreground">
            {t('players.timedBanNote')}
          </p>
        </div>

        <DialogFooter>
          <Button variant="ghost" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button
            variant="destructive"
            disabled={pending || blocked}
            onClick={() =>
              onConfirm({
                reason: reason.trim() === '' ? undefined : reason.trim(),
                durationMinutes,
                includeIp,
              })
            }
          >
            {pending ? t('common.loading') : t('players.ban')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
