import { useTranslation } from 'react-i18next'
import { CircleAlert, Puzzle, RotateCcw } from 'lucide-react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { choicesOf, isEditable, labelOf, tooltipOf, type ConfigValue } from './config'
import { ValueControl } from './value-control'
import type { DraftRow } from './use-config-draft'

/**
 * One setting, with everything needed to change it safely.
 *
 * The game's own explanation sits under the label rather than behind a
 * hover: these are the settings a server stands or falls on, and a
 * reader should not have to hunt for what a value means. Where the game
 * has none the row has none — inventing one would be worse.
 */
export function ValueRow({
  value,
  row,
  onChange,
  onReset,
}: {
  value: ConfigValue
  row: DraftRow
  onChange: (text: string) => void
  onReset: () => void
}) {
  const { t, i18n } = useTranslation()
  const explanation = tooltipOf(value, i18n.language)
  const editable = isEditable(value)

  return (
    <div
      className={`grid gap-3 border-b border-border/60 px-4 py-3 last:border-b-0 sm:grid-cols-[minmax(0,1fr)_18rem] sm:items-start sm:gap-6 ${
        row.changed ? 'bg-primary/5' : ''
      }`}
    >
      <div className="min-w-0 space-y-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-medium">{labelOf(value, i18n.language)}</span>

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

        {/* whitespace-pre-line, because the game's own line breaks are
            part of the sentence: several explanations are two, and run
            together they read as one confused one. */}
        {explanation !== null && (
          <p className="text-muted-foreground text-sm leading-snug whitespace-pre-line">
            {explanation}
          </p>
        )}

        <p className="text-muted-foreground/70 font-mono text-xs">{value.key}</p>
      </div>

      <div className="space-y-1">
        {editable ? (
          <ValueControl
            value={value}
            shown={row.shown}
            invalid={row.invalid || row.outOfBounds}
            onChange={onChange}
          />
        ) : (
          <div className="sm:ml-auto sm:w-64">
            <p className="font-mono text-sm break-words">{String(value.value)}</p>
            <p className="text-muted-foreground text-xs">{t('config.notEditableHere')}</p>
          </div>
        )}

        {/* Says why a value will not be sent, rather than refusing the
            whole save with no explanation on the row that caused it. */}
        {row.invalid && (
          <p className="text-destructive text-xs sm:text-right">{t('config.invalidValue')}</p>
        )}

        {row.outOfBounds && (
          <p className="text-destructive text-xs sm:text-right">
            {t('config.outsideBounds', { min: value.min, max: value.max })}
          </p>
        )}

        {row.changed ? (
          <div className="flex items-center gap-2 sm:justify-end">
            <span className="text-muted-foreground font-mono text-xs">
              {t('config.was', { value: describe(value, value.value, i18n.language) })}
            </span>
            <Button
              variant="ghost"
              size="icon"
              className="size-6"
              aria-label={t('config.resetRow')}
              onClick={onReset}
            >
              <RotateCcw className="size-3" />
            </Button>
          </div>
        ) : value.defaultIsGenerated ? (
          <p className="text-muted-foreground text-xs sm:text-right">
            {t('config.generatedPerServer')}
          </p>
        ) : (
          value.default !== null &&
          String(value.default) !== String(value.value) && (
            <p className="text-muted-foreground text-xs sm:text-right">
              {t('config.defaultIs', { value: describe(value, value.default, i18n.language) })}
            </p>
          )
        )}
      </div>
    </div>
  )
}

/** An enum reads as its label; everything else as itself. */
function describe(
  value: ConfigValue,
  raw: boolean | number | string,
  language: string,
): string {
  if (value.type !== 'enum') {
    return String(raw)
  }

  const choice = choicesOf(value, language).find((option) => option.value === Number(raw))

  return choice?.label ?? String(raw)
}
