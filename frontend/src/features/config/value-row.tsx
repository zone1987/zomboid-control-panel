import { useTranslation } from 'react-i18next'
import { CircleAlert, Puzzle } from 'lucide-react'

import { Badge } from '@/components/ui/badge'
import { Switch } from '@/components/ui/switch'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { choicesOf, labelOf, tooltipOf, type ConfigValue } from './config'

/**
 * One setting, shown with everything needed to change it safely.
 *
 * The game's own explanation sits under the label rather than behind a
 * hover, because these are the settings a server stands or falls on and
 * a reader should not have to hunt for what a value means. Where the
 * game has no explanation the row simply has none — inventing one would
 * be worse.
 *
 * Read-only for now: the write path lands with the save mechanism, and
 * a control that looks editable and is not would be a lie.
 */
export function ValueRow({ value }: { value: ConfigValue }) {
  const { t, i18n } = useTranslation()
  const language = i18n.language
  const explanation = tooltipOf(value, language)

  return (
    <div className="grid gap-3 border-b border-border/60 px-4 py-3 last:border-b-0 sm:grid-cols-[minmax(0,1fr)_18rem] sm:items-start sm:gap-6">
      <div className="min-w-0 space-y-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-medium">{labelOf(value, language)}</span>

          {!value.known && (
            <Tooltip>
              <TooltipTrigger asChild>
                <Badge variant="secondary" className="gap-1">
                  <Puzzle className="size-3" aria-hidden />
                  {t('config.fromAMod')}
                </Badge>
              </TooltipTrigger>
              <TooltipContent>{t('config.fromAModHint')}</TooltipContent>
            </Tooltip>
          )}

          {value.outOfRange && (
            <Tooltip>
              <TooltipTrigger asChild>
                <Badge variant="outline" className="gap-1 text-amber-600 dark:text-amber-400">
                  <CircleAlert className="size-3" aria-hidden />
                  {t('config.unrecognised')}
                </Badge>
              </TooltipTrigger>
              <TooltipContent>{t('config.unrecognisedHint')}</TooltipContent>
            </Tooltip>
          )}
        </div>

        {explanation !== null && (
          <p className="text-muted-foreground text-sm leading-snug">{explanation}</p>
        )}

        <p className="text-muted-foreground/70 font-mono text-xs">{value.key}</p>
      </div>

      <div className="space-y-1 sm:text-right">
        <Shown value={value} />

        {/* The default is worth knowing when it differs, and worth not
            claiming when the game makes one up per server. */}
        {value.defaultIsGenerated ? (
          <p className="text-muted-foreground text-xs">{t('config.generatedPerServer')}</p>
        ) : (
          value.default !== null &&
          String(value.default) !== String(value.value) && (
            <p className="text-muted-foreground text-xs">
              {t('config.defaultIs', { value: describe(value, value.default, language) })}
            </p>
          )
        )}
      </div>
    </div>
  )
}

/** The stored value, rendered as what it means rather than as a number. */
function Shown({ value }: { value: ConfigValue }) {
  const { i18n } = useTranslation()

  if (value.type === 'boolean') {
    return (
      <div className="sm:flex sm:justify-end">
        <Switch checked={value.value === true} disabled aria-readonly />
      </div>
    )
  }

  const rendered = describe(value, value.value, i18n.language)

  return (
    <p className="font-mono text-sm break-words">
      {rendered}
      {value.min !== null && value.type !== 'enum' && (
        <span className="text-muted-foreground ml-2 font-sans text-xs">
          {value.min} – {value.max}
        </span>
      )}
    </p>
  )
}

/** An enum reads as its label; everything else as itself. */
function describe(
  value: ConfigValue,
  raw: boolean | number | string,
  language: string,
): string {
  if (value.type !== 'enum') {
    return typeof raw === 'boolean' ? String(raw) : String(raw)
  }

  const choice = choicesOf(value, language).find((option) => option.value === Number(raw))

  return choice === undefined ? String(raw) : `${choice.label}`
}
