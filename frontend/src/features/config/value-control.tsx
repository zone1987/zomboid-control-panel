import { useTranslation } from 'react-i18next'

import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { choicesOf, labelOf, type ConfigValue } from './config'

/**
 * The control for one setting, chosen by the type the game declares.
 *
 * An enum gets its own names rather than a number, because "Schlurfer"
 * is a setting and "3" is a riddle. A bounded number states its range
 * beside the field, since the game clamps silently and a value it would
 * refuse should be visible as such before it is sent.
 */
export function ValueControl({
  value,
  shown,
  invalid,
  onChange,
}: {
  value: ConfigValue
  shown: boolean | number | string
  invalid: boolean
  onChange: (next: string) => void
}) {
  const { t, i18n } = useTranslation()
  const label = labelOf(value, i18n.language)

  if (value.type === 'boolean') {
    return (
      <div className="flex items-center gap-2 sm:justify-end">
        <Switch
          checked={shown === true}
          onCheckedChange={(next) => onChange(next ? 'true' : 'false')}
          aria-label={label}
        />
        <span className="text-muted-foreground text-sm">
          {t(shown === true ? 'common.on' : 'common.off')}
        </span>
      </div>
    )
  }

  if (value.type === 'enum') {
    const choices = choicesOf(value, i18n.language)
    const current = String(shown)
    // A stored value outside the known set is never coerced to a
    // default: it stays selected and says what it is.
    const unknown = !choices.some((choice) => String(choice.value) === current)

    return (
      <Select value={current} onValueChange={onChange}>
        <SelectTrigger className="w-full sm:ml-auto sm:w-64" aria-label={label}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {unknown && (
            <SelectItem value={current}>
              {t('config.unrecognisedChoice', { value: current })}
            </SelectItem>
          )}
          {choices.map((choice) => (
            <SelectItem key={choice.value} value={String(choice.value)}>
              {choice.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    )
  }

  const numeric = value.type === 'integer' || value.type === 'double'

  return (
    <div className="space-y-1 sm:ml-auto sm:w-64">
      <Input
        value={String(shown)}
        onChange={(event) => onChange(event.target.value)}
        inputMode={numeric ? 'decimal' : undefined}
        aria-label={label}
        aria-invalid={invalid}
        className={invalid ? 'border-destructive' : undefined}
      />

      {numeric && value.min !== null && (
        <p className="text-muted-foreground text-xs sm:text-right">
          {value.min} – {value.max}
        </p>
      )}
    </div>
  )
}
