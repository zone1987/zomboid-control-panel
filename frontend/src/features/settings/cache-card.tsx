import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { CheckCircle2, DatabaseZap, RefreshCw, TriangleAlert } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { clearServerCache, type CacheClearResult } from './settings'

/**
 * Drops what the panel read from the game server.
 *
 * On Coolify the operator has no console, so a pool entry that went
 * stale has no other way out: a failed translation read was held for a
 * week and the panel showed English item names the whole time.
 */
export function CacheCard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [outcome, setOutcome] = useState<CacheClearResult | null>(null)

  const clear = useMutation({
    mutationFn: clearServerCache,
    onSuccess: (result) => {
      setOutcome(result)
      void queryClient.invalidateQueries()
      toast.success(
        result.state === 'nothingToClear'
          ? t('settings.cacheNothing')
          : t('settings.cacheCleared', { count: result.keys }),
      )
    },
    onError: (error) => {
      setOutcome(null)
      toast.error(error instanceof ApiError ? error.message : t('settings.cacheFailed'))
    },
  })

  return (
    <Card className="max-w-xl">
      <CardHeader>
        <CardTitle>{t('settings.cacheTitle')}</CardTitle>
        <CardDescription>{t('settings.cacheDescription')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <div className="text-muted-foreground space-y-2 text-sm">
          <p>{t('settings.cacheWhatItDrops')}</p>
          <p>{t('settings.cacheWhatItKeeps')}</p>
        </div>

        <Button
          variant="outline"
          disabled={clear.isPending}
          onClick={() => clear.mutate()}
        >
          {clear.isPending ? (
            <RefreshCw className="size-4 animate-spin" />
          ) : (
            <DatabaseZap className="size-4" />
          )}
          {clear.isPending ? t('settings.cacheClearing') : t('settings.cacheClear')}
        </Button>

        {outcome !== null && outcome.state !== 'failed' && (
          <Alert variant="success">
            <CheckCircle2 className="size-4" />
            <AlertDescription>
              {outcome.state === 'nothingToClear'
                ? t('settings.cacheNothing')
                : outcome.state === 'partiallyCleared'
                  ? t('settings.cachePartial')
                  : t('settings.cacheClearedDetail', {
                      count: outcome.keys,
                      servers: outcome.servers,
                    })}
            </AlertDescription>
          </Alert>
        )}

        {outcome?.state === 'failed' && (
          <Alert variant="destructive">
            <TriangleAlert className="size-4" />
            <AlertDescription>
              {t('settings.cacheFailed')}
              {outcome.detail ? ` — ${outcome.detail}` : ''}
            </AlertDescription>
          </Alert>
        )}
      </CardContent>
    </Card>
  )
}
