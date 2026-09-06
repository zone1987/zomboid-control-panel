import { useTranslation } from 'react-i18next'

import { Input } from '@/components/ui/input'
import { Slider } from '@/components/ui/slider'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { Player } from '@/features/players/players'
import { formatRange } from './units'
import type { EventAction, EventField } from './events'

export function EventForm({
  action,
  values,
  players,
  onChange,
}: {
  action: EventAction
  values: Record<string, string | number | boolean>
  players: Player[]
  onChange: (name: string, value: string | number | boolean) => void
}) {
  const { t } = useTranslation()

  if (action.fields.length === 0) {
    return null
  }

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {action.fields.map((field) => (
        <div key={field.name} className="space-y-1.5">
          <Label htmlFor={`event-${field.name}`}>
            {t(`events.fields.${field.name}`, { defaultValue: field.name })}
            {field.required === false && (
              <span className="ml-1 text-xs font-normal text-muted-foreground">
                {t('events.optional')}
              </span>
            )}
          </Label>

          <FieldInput
            field={field}
            value={values[field.name]}
            players={players}
            onChange={(value) => onChange(field.name, value)}
          />

          {field.type === 'number' && field.min !== undefined && field.max !== undefined && (
            <p className="font-mono text-xs tabular-nums text-muted-foreground">
              {formatRange(field.min, field.max)}
            </p>
          )}
        </div>
      ))}
    </div>
  )
}

function FieldInput({
  field,
  value,
  players,
  onChange,
}: {
  field: EventField
  value: string | number | boolean | undefined
  players: Player[]
  onChange: (value: string | number | boolean) => void
}) {
  const { t } = useTranslation()
  const id = `event-${field.name}`

  // A yes-or-no input: a kind, not an amount.
  if (field.type === 'toggle') {
    return (
      <Switch
        id={id}
        checked={value === true}
        aria-label={t(`events.fields.${field.name}`, { defaultValue: field.name })}
        onCheckedChange={onChange}
      />
    )
  }

  if (field.type === 'player') {
    const online = players.filter((player) => player.online)

    return (
      <Select value={String(value ?? '') || undefined} onValueChange={onChange}>
        <SelectTrigger id={id} className="w-full" disabled={online.length === 0}>
          <SelectValue
            placeholder={online.length === 0 ? t('events.noPlayers') : t('events.choosePlayer')}
          />
        </SelectTrigger>
        <SelectContent>
          {online.map((player) => (
            <SelectItem key={player.username} value={player.username}>
              {player.username}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    )
  }

  if (field.type === 'choice') {
    const options = field.choices ?? []

    return (
      <div className="space-y-1.5">
        <Input
          id={id}
          value={String(value ?? '')}
          onChange={(event) => onChange(event.target.value)}
        />

        <Select value={undefined} onValueChange={onChange}>
          <SelectTrigger className="h-8 w-full text-xs">
            <SelectValue placeholder={t('events.pickFromList')} />
          </SelectTrigger>
          <SelectContent>
            {options.map((option) => (
              <SelectItem key={option} value={option}>
                {option}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
    )
  }

  if (field.type === 'number') {
    // A bounded number gets a slider beside its field: an intensity is
    // "a bit more rain" as often as it is a figure somebody knows, and
    // aiming a slider at 70 is worse than typing 70.
    if (field.min !== undefined && field.max !== undefined) {
      const current = Number.parseInt(String(value ?? ''), 10)
      const settled = Number.isNaN(current) ? field.min : current

      return (
        <div className="flex items-center gap-3">
          <Slider
            min={field.min}
            max={field.max}
            value={[Math.min(field.max, Math.max(field.min, settled))]}
            aria-label={t(`events.fields.${field.name}`, { defaultValue: field.name })}
            onValueChange={([next]) => onChange(next ?? field.min ?? 0)}
          />

          <div className="flex shrink-0 items-center gap-1">
            <Input
              id={id}
              inputMode="numeric"
              className="w-16 text-center font-mono tabular-nums"
              value={String(value ?? '')}
              onChange={(event) => onChange(event.target.value)}
            />

            {field.unit !== undefined && (
              <span className="w-9 font-mono text-xs text-muted-foreground">{field.unit}</span>
            )}
          </div>
        </div>
      )
    }

    return (
      <div className="flex items-center gap-1">
        <Input
          id={id}
          type="number"
          inputMode="numeric"
          value={String(value ?? '')}
          onChange={(event) => onChange(event.target.value)}
        />

        {field.unit !== undefined && (
          <span className="shrink-0 font-mono text-xs text-muted-foreground">{field.unit}</span>
        )}
      </div>
    )
  }

  return (
    <Input
      id={id}
      value={String(value ?? '')}
      maxLength={field.maxLength}
      onChange={(event) => onChange(event.target.value)}
    />
  )
}
