import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowUpCircle } from 'lucide-react'

import { cn } from '@/lib/utils'
import { getPanelVersion, hasUpdate } from '@/features/panel/panel-version'

/** How long a release lookup is trusted in the browser. */
const STALE_MS = 3_600_000

/**
 * The panel's own version, with a link when a newer release exists.
 *
 * Silent when the lookup fails: not knowing whether an update exists is
 * not a fault worth putting in front of an operator.
 */
export function PanelVersionLine({
  className,
  labelled = false,
}: {
  className?: string
  /** Prefixes "Panel version", so the label cannot outlive the value. */
  labelled?: boolean
}) {
  const { t } = useTranslation()

  const { data } = useQuery({
    queryKey: ['panel-version'],
    queryFn: getPanelVersion,
    retry: false,
    staleTime: STALE_MS,
  })

  if (data === undefined) {
    return null
  }

  const label = labelled ? <span className="font-sans">{t('panel.version')} </span> : null

  if (!hasUpdate(data)) {
    return (
      <p className={cn('font-mono text-xs text-muted-foreground', className)}>
        {label}v{data.current}
      </p>
    )
  }

  return (
    <a
      href={data.url ?? undefined}
      target="_blank"
      rel="noreferrer"
      className={cn(
        'flex items-center gap-1.5 font-mono text-xs text-primary hover:underline',
        className,
      )}
      title={t('panel.updateAvailable', { version: data.latest })}
    >
      <ArrowUpCircle className="size-3" />
      {label}v{data.current} → v{data.latest}
    </a>
  )
}
