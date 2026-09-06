import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Check, Copy } from 'lucide-react'

import { cn } from '@/lib/utils'

/**
 * A machine value with a copy button: a script name, a SteamID, a
 * coordinate.
 *
 * These are values people paste into a console or a ticket rather than
 * read, and `Base.StepVan_LouisvilleSWAT` is not something anybody
 * retypes correctly.
 */
export function Copyable({
  value,
  label,
  className,
}: {
  value: string
  /** Shown instead of the value, when the value itself is too long. */
  label?: string
  className?: string
}) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)
  const timer = useRef<number | null>(null)

  useEffect(
    () => () => {
      if (timer.current !== null) {
        window.clearTimeout(timer.current)
      }
    },
    [],
  )

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(value)
      setCopied(true)

      if (timer.current !== null) {
        window.clearTimeout(timer.current)
      }

      timer.current = window.setTimeout(() => setCopied(false), 2000)
    } catch {
      // Clipboard access can be denied; the value stays readable on screen.
    }
  }

  return (
    <button
      type="button"
      // The value is announced, not the icon: a screen reader should hear
      // what is being copied.
      aria-label={`${t('common.copy')}: ${value}`}
      title={copied ? t('common.copied') : t('common.copy')}
      className={cn(
        'pz-interactive group inline-flex max-w-full items-center gap-1.5 rounded-sm text-left font-mono text-xs text-muted-foreground hover:text-foreground',
        className,
      )}
      onClick={() => void copy()}
    >
      <span className="truncate">{label ?? value}</span>

      {copied ? (
        <Check className="size-3 shrink-0 text-primary" />
      ) : (
        <Copy className="size-3 shrink-0 opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100" />
      )}
    </button>
  )
}
