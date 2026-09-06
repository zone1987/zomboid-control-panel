import { isRouteErrorResponse, useRouteError } from 'react-router'
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
  const detail = describe(error)

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

/**
 * The folded-away detail, in words rather than "[object Object]".
 *
 * React Router throws an ErrorResponse for a route that does not match
 * -- a plain object, not an Error -- and String() on it yields
 * "[object Object]". That is the one line an operator is asked to send
 * when reporting a problem, so it has to say something.
 */
function describe(error: unknown): string {
  if (isRouteErrorResponse(error)) {
    return `${error.status} ${error.statusText}${error.data ? `: ${String(error.data)}` : ''}`
  }

  if (error instanceof Error) {
    return error.message
  }

  if (typeof error === 'object' && error !== null) {
    try {
      return JSON.stringify(error)
    } catch {
      // Circular or otherwise unserialisable; the fallback still beats
      // "[object Object]".
    }
  }

  return String(error)
}
