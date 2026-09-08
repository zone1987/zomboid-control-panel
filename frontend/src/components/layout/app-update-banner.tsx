import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { RefreshCw } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { getPanelVersion } from '@/features/panel/panel-version'
import { reloadOntoTheNewBuild, useAppUpdate } from '@/features/panel/use-app-update'

/**
 * Often enough to notice a rollout while it happens.
 *
 * The dashboard and the footer read the same query with an hour's
 * stale time; this interval refreshes it for all three, which is the
 * point -- a deployment in flight is worth knowing about everywhere,
 * and one small request every half minute is not a cost.
 */
const POLL_MS = 30_000

/**
 * Offers a reload once a newer build is waiting, and says when the
 * panel is updating itself.
 *
 * A button in the top bar rather than a dialog: nothing is broken, and
 * interrupting an operator mid-action to announce good news is rude.
 * The exception is a deployment the panel itself started -- there the
 * page is about to be replaced either way, so saying so beats a
 * restart nobody explained.
 */
export function AppUpdateBanner() {
  const { t } = useTranslation()
  const { ready, apply } = useAppUpdate()

  const { data: version } = useQuery({
    queryKey: ['panel-version'],
    queryFn: getPanelVersion,
    refetchInterval: POLL_MS,
    refetchIntervalInBackground: true,
    retry: false,
    staleTime: POLL_MS,
  })

  const deploying = version?.deploying === true
  const takesItself = deploying && version?.reloadPanel === true

  // The worker still answers from its precache after a deployment, so a
  // plain reload would come back on the old build and then offer the
  // update button -- a second step after something the panel did itself.
  useEffect(() => {
    if (!ready || !takesItself) {
      return
    }

    void reloadOntoTheNewBuild()
  }, [ready, takesItself])

  if (deploying) {
    const notice = t(
      version?.reloadPanel === true
        ? 'settings.deploy.deployingNotice'
        : 'settings.deploy.deployingNoticeManual',
    )

    // The spinner alone on a phone: the sentence is 90 characters and
    // the bar has room for the language and theme buttons first. Its
    // title and label carry the wording where it cannot be shown.
    return (
      <span
        className="text-muted-foreground flex min-w-0 items-center gap-1.5 text-xs"
        title={notice}
      >
        <RefreshCw className="size-3.5 shrink-0 animate-spin" aria-hidden="true" />
        <span className="hidden truncate lg:inline">{notice}</span>
        <span className="sr-only">{notice}</span>
      </span>
    )
  }

  if (!ready) {
    return null
  }

  return (
    <Button
      variant="outline"
      size="sm"
      // The label is ~150px, which on a phone pushed the language and
      // theme buttons off the right edge of the bar.
      className="size-9 gap-1.5 p-0 sm:h-8 sm:w-auto sm:px-3"
      title={t('panel.reloadForUpdate')}
      aria-label={t('panel.reloadForUpdate')}
      onClick={apply}
    >
      <RefreshCw className="size-3.5" />
      <span className="hidden sm:inline">{t('panel.reloadForUpdate')}</span>
    </Button>
  )
}
