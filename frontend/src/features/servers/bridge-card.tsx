import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowUpCircle, CircleCheckBig, TriangleAlert, Upload } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { listPlayers } from '@/features/players/players'
import { getBridgeStatus, installBridge, type GameServer } from './servers'

export function BridgeCard({ server }: { server: GameServer }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const hasPath = Boolean(server.ftp?.luaServerPath)

  const { data: status, isPending } = useQuery({
    queryKey: ['bridge-status', server.id],
    queryFn: () => getBridgeStatus(server.id),
    enabled: hasPath,
    retry: false,
    // Re-read from the server so an upload or a restart shows up without
    // the page being reloaded.
    refetchInterval: 30_000,
    refetchOnWindowFocus: true,
    placeholderData: (previous) => previous,
  })

  // What the running server loaded, which lags the file until a restart.
  const { data: live } = useQuery({
    queryKey: ['players', server.id, false],
    queryFn: () => listPlayers(server.id, false),
    enabled: hasPath,
    retry: false,
    refetchInterval: 15_000,
    placeholderData: (previous) => previous,
  })

  const runningVersion = live?.bridge?.version ?? null

  const upload = useMutation({
    mutationFn: () => installBridge(server.id),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: ['bridge-status', server.id] })
      toast.success(t('servers.bridgeUploaded', { version: result.version }), {
        description: t('servers.bridgeRestartHint'),
      })
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError && error.status === 409
          ? t('servers.bridgeNeedsPath')
          : t('servers.bridgeUploadFailed'),
      )
    },
  })

  // Up to date, out of date, or not there at all — each wants a different
  // button, but the two versions are shown in every case.
  const state = !hasPath
    ? 'noPath'
    : status === undefined
      ? 'unknown'
      : status.error !== null
        ? 'unreachable'
        : !status.installed
          ? 'missing'
          : status.upToDate
            ? 'current'
            : 'outdated'

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('servers.bridgeTitle')}</CardTitle>
        <CardDescription>{t('servers.bridgeDescription')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        {isPending && hasPath ? (
          <Skeleton className="h-16 w-full" />
        ) : (
          <div className="flex flex-wrap items-center gap-x-8 gap-y-3">
            <Version
              label={t('servers.bridgeInstalledVersion')}
              value={
                status?.installed === true
                  ? (status.installedVersion ?? t('common.unknown'))
                  : state === 'missing'
                    ? t('servers.bridgeNotInstalled')
                    : t('common.unknown')
              }
              muted={state !== 'current' && state !== 'outdated'}
            />

            <Version
              label={t('servers.bridgeAvailableVersion')}
              value={status?.availableVersion ?? t('common.unknown')}
            />

            {state === 'current' && (
              <Badge variant="secondary" className="gap-1">
                <CircleCheckBig className="size-3.5 text-emerald-500" />
                {t('servers.bridgeUpToDate')}
              </Badge>
            )}

            {state === 'outdated' && (
              <Badge variant="default" className="gap-1">
                <ArrowUpCircle className="size-3.5" />
                {t('servers.bridgeUpdateAvailable')}
              </Badge>
            )}
          </div>
        )}

        {/* The file on disk can be newer than what the running server
            loaded, which is exactly the state a restart resolves. */}
        {state === 'current' &&
          runningVersion !== null &&
          status?.installedVersion !== null &&
          runningVersion !== status?.installedVersion && (
            <p className="flex items-start gap-2 text-sm text-amber-600 dark:text-amber-500">
              <TriangleAlert className="mt-0.5 size-4 shrink-0" />
              {t('servers.bridgeRestartPending', {
                running: runningVersion,
                installed: status?.installedVersion ?? '',
              })}
            </p>
          )}

        {state === 'missing' && (
          <Button disabled={upload.isPending} onClick={() => upload.mutate()}>
            <Upload className="size-4" />
            {upload.isPending ? t('common.loading') : t('servers.uploadBridge')}
          </Button>
        )}

        {state === 'outdated' && (
          <Button disabled={upload.isPending} onClick={() => upload.mutate()}>
            <ArrowUpCircle className="size-4" />
            {upload.isPending ? t('common.loading') : t('servers.updateBridge')}
          </Button>
        )}

        {/* Reinstalling a current bridge is a repair, not a routine step,
            so it stays available but quiet. */}
        {state === 'current' && (
          <Button variant="ghost" size="sm" disabled={upload.isPending} onClick={() => upload.mutate()}>
            <Upload className="size-4" />
            {upload.isPending ? t('common.loading') : t('servers.reuploadBridge')}
          </Button>
        )}

        {state === 'unreachable' && (
          <p className="flex items-start gap-2 text-sm text-amber-600 dark:text-amber-500">
            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
            {t('servers.bridgeStatusUnknown')}
          </p>
        )}

        {state === 'noPath' && (
          <p className="text-sm text-muted-foreground">{t('servers.bridgeNeedsPath')}</p>
        )}

        {(state === 'missing' || state === 'outdated') && (
          <p className="text-sm text-muted-foreground">{t('servers.bridgeRestartHint')}</p>
        )}

        {status?.path != null && (
          <p className="text-xs text-muted-foreground">
            <code className="font-mono">{status.path}</code>
          </p>
        )}
      </CardContent>
    </Card>
  )
}

function Version({ label, value, muted = false }: { label: string; value: string; muted?: boolean }) {
  return (
    <div>
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className={muted ? 'font-mono text-sm text-muted-foreground' : 'font-mono text-sm font-medium'}>
        {value}
      </p>
    </div>
  )
}
