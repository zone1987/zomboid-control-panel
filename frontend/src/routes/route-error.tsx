import { useRouteError } from 'react-router'
import { useTranslation } from 'react-i18next'
import { RefreshCw, TriangleAlert } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { looksLikeStaleChunk } from './lazy-route'

/**
 * What an operator sees when a route fails.
 *
 * Not a stack trace: the people running this panel are server
 * operators, not developers, and "Failed to fetch dynamically imported
 * module" tells them nothing they can act on. The detail is still there
 * for anyone reporting the problem, folded away.
 */
export function RouteError() {
  const { t } = useTranslation()
  const error = useRouteError()

  const stale = looksLikeStaleChunk(error)
  const detail = error instanceof Error ? error.message : String(error)

  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center gap-4 p-6 text-center">
      <TriangleAlert className="size-10 text-muted-foreground" />

      <div className="space-y-1">
        <h1 className="text-lg font-medium">
          {stale ? t('errors.outdatedTitle') : t('errors.routeTitle')}
        </h1>
        <p className="max-w-md text-sm text-muted-foreground">
          {stale ? t('errors.outdatedBody') : t('errors.routeBody')}
        </p>
      </div>

      <Button onClick={() => window.location.reload()}>
        <RefreshCw className="size-4" />
        {t('errors.reload')}
      </Button>

      <details className="max-w-full">
        <summary className="cursor-pointer text-xs text-muted-foreground">
          {t('errors.showDetail')}
        </summary>
        <pre className="mt-2 max-w-md overflow-x-auto rounded-md border bg-muted/40 p-3 text-left text-xs">
          {detail}
        </pre>
      </details>
    </div>
  )
}
