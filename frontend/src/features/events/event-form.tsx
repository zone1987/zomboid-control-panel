import { useTranslation } from 'react-i18next'

import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { Player } from '@/features/players/players'
import type { EventAction, EventField } from './events'

export function EventForm({
  action,
  values,
  players,
  vehicles,
  onChange,
}: {
  action: EventAction
  values: Record<string, string | number | boolean>
  players: Player[]
  vehicles: string[]
  onChange: (name: string, value: string | number) => void
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
            action={action}
            field={field}
            value={values[field.name]}
            players={players}
            vehicles={vehicles}
            onChange={(value) => onChange(field.name, value)}
          />

          {field.type === 'number' && field.min !== undefined && field.max !== undefined && (
            <p className="text-xs text-muted-foreground">
              {t('events.range', { min: field.min, max: field.max })}
            </p>
          )}
        </div>
      ))}
    </div>
  )
}

function FieldInput({
  action,
  field,
  value,
  players,
  vehicles,
  onChange,
}: {
  action: EventAction
  field: EventField
  value: string | number | boolean | undefined
  players: Player[]
  vehicles: string[]
  onChange: (value: string | number) => void
}) {
  const { t } = useTranslation()
  const id = `event-${field.name}`

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
    // A modded server has vehicles the panel does not list, so the
    // dropdown is a shortcut into a field that still takes any name.
    const options = action.id === 'spawnVehicle' ? vehicles : (field.choices ?? [])

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
    return (
      <Input
        id={id}
        type="number"
        inputMode="numeric"
        min={field.min}
        max={field.max}
        value={String(value ?? '')}
        onChange={(event) => onChange(event.target.value)}
      />
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
