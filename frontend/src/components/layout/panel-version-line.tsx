import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowUpCircle } from 'lucide-react'

import { getPanelVersion, hasUpdate } from '@/features/panel/panel-version'

/** How long a release lookup is trusted in the browser. */
const STALE_MS = 3_600_000

/**
 * The panel's own version, with a link when a newer release exists.
 *
 * Silent when the lookup fails: not knowing whether an update exists is
 * not a fault worth putting in front of an operator.
 */
export function PanelVersionLine() {
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

  if (!hasUpdate(data)) {
    return (
      <p className="px-2 pb-1 font-mono text-xs text-muted-foreground group-data-[collapsible=icon]:hidden">
        v{data.current}
      </p>
    )
  }

  return (
    <a
      href={data.url ?? undefined}
      target="_blank"
      rel="noreferrer"
      className="flex items-center gap-1.5 px-2 pb-1 font-mono text-xs text-primary hover:underline group-data-[collapsible=icon]:hidden"
      title={t('panel.updateAvailable', { version: data.latest })}
    >
      <ArrowUpCircle className="size-3" />
      v{data.current} → v{data.latest}
    </a>
  )
}
