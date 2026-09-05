import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Check, Layers, Play, TriangleAlert } from 'lucide-react'

import { errorField } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { listServers } from '@/features/servers/servers'
import { renderProgress, startWorldRender, watchRender, type RenderProgress } from './render-progress'

function gigabytes(bytes: number): string {
  return bytes < 1073741824
    ? `${(bytes / 1048576).toFixed(0)} MB`
    : `${(bytes / 1073741824).toFixed(1)} GB`
}

function elapsed(from: number): string {
  const total = Math.max(0, Math.floor(Date.now() / 1000) - from)
  const hours = Math.floor(total / 3600)
  const minutes = Math.floor((total % 3600) / 60)

  return hours > 0 ? `${hours} h ${minutes} min` : `${minutes} min`
}

export function WorldRenderCard({ serverId }: { serverId: string | null }) {
  const { t } = useTranslation()

  // The settings page has no server in its URL, and a fresh browser has
  // none remembered either -- but a panel with one server has an
  // obvious answer, so the card finds it rather than staying disabled.
  const [dismissed, setDismissed] = useState(false)
  const { data: servers } = useQuery({ queryKey: ['servers'], queryFn: listServers })
  const target = serverId ?? servers?.items[0]?.id ?? null
  const [progress, setProgress] = useState<RenderProgress>({ state: 'idle' })
  const stop = useRef<(() => void) | null>(null)

  useEffect(() => {
    let alive = true

    const ask = () => {
      void renderProgress()
        .then((state) => alive && setProgress(state))
        .catch(() => undefined)
    }

    ask()

    const timer = window.setInterval(ask, 2_000)
    stop.current = watchRender(setProgress, () => undefined)

    return () => {
      alive = false
      window.clearInterval(timer)
      stop.current?.()
    }
  }, [])

  const start = useMutation({
    mutationFn: () => startWorldRender(target ?? ''),
    onSuccess: () => {
      setDismissed(true)
      toast.success(t('settings.render.started'))
    },
    onError: (error) => {
      const key = errorField(error, 'error')

      toast.error(key === null ? t('errors.generic') : t(`settings.render.${key.split('.').pop()}`, t('errors.generic')))
    },
  })

  const running = progress.state === 'running'
  const total = progress.cellsTotal ?? 0
  const done = progress.cellsDone ?? 0
  const percent = total === 0 ? 0 : Math.round((done / total) * 100)

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settings.render.title')}</CardTitle>
        <CardDescription>{t('settings.render.description')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        {progress.state === 'done' && (
          <Alert>
            <Check className="size-4" />
            <AlertTitle>{t('settings.render.finished')}</AlertTitle>
            <AlertDescription>
              {t('settings.render.finishedBody', {
                cells: progress.cellsRendered ?? 0,
                tiles: (progress.tilesUploaded ?? 0).toLocaleString(),
                size: gigabytes(progress.bytesUploaded ?? 0),
              })}
            </AlertDescription>
          </Alert>
        )}

        {progress.state === 'failed' && !dismissed && (
          <Alert variant="destructive">
            <TriangleAlert className="size-4" />
            <AlertTitle>{t('settings.render.failed')}</AlertTitle>
            <AlertDescription className="flex items-start justify-between gap-3">
              <span>
                {t(`settings.render.${(progress.error ?? '').split('.').pop()}`, t('errors.generic'))}
              </span>

              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="-my-1 shrink-0"
                onClick={() => setDismissed(true)}
              >
                {t('common.dismiss')}
              </Button>
            </AlertDescription>
          </Alert>
        )}

        {running && (
          <div className="space-y-3 rounded-lg border p-4">
            <div className="flex items-baseline justify-between gap-3">
              <span className="text-sm font-medium">
                {t(`settings.render.phase.${progress.phase ?? 'starting'}`, progress.phase ?? '')}
              </span>
              <span className="text-sm tabular-nums text-muted-foreground">
                {percent}% · {t('settings.render.running', { time: elapsed(progress.startedAt ?? 0) })}
              </span>
            </div>

            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
              <div
                className="h-full bg-primary transition-[width] duration-300"
                style={{ width: `${percent}%` }}
              />
            </div>

            <dl className="grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm sm:grid-cols-4">
              <Figure label={t('settings.render.cells')} value={`${done.toLocaleString()} / ${total.toLocaleString()}`} />
              <Figure label={t('settings.render.rendered')} value={(progress.cellsRendered ?? 0).toLocaleString()} />
              <Figure label={t('settings.render.tiles')} value={(progress.tilesUploaded ?? 0).toLocaleString()} />
              <Figure label={t('settings.render.uploaded')} value={gigabytes(progress.bytesUploaded ?? 0)} />
            </dl>

            {/* The tile name going past is how somebody tells a working
                render from a stuck one. */}
            <p className="truncate font-mono text-xs text-muted-foreground">
              {progress.currentCell && `${t('settings.render.cell')} ${progress.currentCell} · `}
              {progress.currentTile || '…'}
            </p>

            {(progress.tilesFailed ?? 0) > 0 && (
              <p className="text-xs text-amber-600 dark:text-amber-500">
                {t('settings.render.someFailed', { count: progress.tilesFailed ?? 0 })}
              </p>
            )}
          </div>
        )}

        <Alert>
          <Layers className="size-4" />
          <AlertTitle>{t('settings.render.cost')}</AlertTitle>
          <AlertDescription>{t('settings.render.costBody')}</AlertDescription>
        </Alert>

        <Button type="button" disabled={running || target === null || start.isPending} onClick={() => start.mutate()}>
          <Play className="size-4" />
          {running ? t('settings.render.inProgress') : t('settings.render.start')}
        </Button>
      </CardContent>
    </Card>
  )
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="tabular-nums">{value}</dd>
    </div>
  )
}
