import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Loader2, Pause, Play, TriangleAlert, X } from 'lucide-react'
import { useMutation } from '@tanstack/react-query'
import { toast } from 'sonner'

import { Link } from 'react-router'

import { Button } from '@/components/ui/button'

import {
  pauseWorldRender,
  renderProgress,
  stopWorldRender,
  watchRender,
  type RenderProgress,
} from '@/features/settings/render-progress'

function size(bytes: number): string {
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

/**
 * What the render is doing, over the map it is producing.
 *
 * The map cannot be used while it is being drawn -- what is there is a
 * fragment -- so the progress belongs on top of it rather than on a
 * settings page nobody would sit and watch.
 */
export function RenderOverlay({ hasRender = true }: { hasRender?: boolean }) {
  const { t } = useTranslation()
  const [progress, setProgress] = useState<RenderProgress>({ state: 'idle' })

  // Closed on the click, not on the answer: the run is killed server
  // side, and a window that lingers while uploads cost money reads as a
  // button that did nothing.
  const [stopped, setStopped] = useState(false)

  const stopRun = useMutation({
    mutationFn: stopWorldRender,
    onSuccess: () => toast.success(t('map.render.stopped')),
    onError: () => toast.error(t('errors.generic')),
  })

  const holdRun = useMutation({
    mutationFn: (resume: boolean) => pauseWorldRender(resume),
    onError: () => toast.error(t('errors.generic')),
  })

  useEffect(() => {
    let alive = true

    // Asked once up front: a stream only reports the next change, so a
    // render already running would otherwise stay invisible until it
    // moved. Polling continues alongside because a proxy that buffers
    // the stream -- Vite's dev server does -- would show nothing at all.
    const ask = () => {
      void renderProgress()
        .then((state) => {
          if (alive) {
            setProgress(state)
          }
        })
        .catch(() => undefined)
    }

    ask()

    const timer = window.setInterval(ask, 2_000)
    const stop = watchRender(setProgress, () => undefined)

    return () => {
      alive = false
      window.clearInterval(timer)
      stop()
    }
  }, [])

  // Nothing rendered and nothing running: the map behind this is black,
  // so the box says what to do rather than leaving an empty page.
  if (stopped) {
    return null
  }

  if (progress.state !== 'running') {
    if (hasRender) {
      return null
    }

    return (
      <div className="absolute inset-0 z-30 flex items-center justify-center bg-black/60 p-4 backdrop-blur-[2px]">
        <div className="w-full max-w-md space-y-3 rounded-xl border bg-background p-6 shadow-2xl">
          <h2 className="font-semibold">{t('map.render.nothingYet')}</h2>
          <p className="text-sm text-muted-foreground">{t('map.render.nothingYetHint')}</p>

          <Button asChild size="sm">
            <Link to="/app/settings">{t('map.render.goToSettings')}</Link>
          </Button>
        </div>
      </div>
    )
  }

  const paused = progress.paused === true
  const stopping = progress.stopRequested === true

  const total = progress.cellsTotal ?? 0
  const done = progress.cellsDone ?? 0
  const percent = total === 0 ? 0 : Math.round((done / total) * 100)

  return (
    // The map underneath is a fragment while this runs, so it is dimmed
    // rather than left competing for attention.
    <div className="absolute inset-0 z-30 flex items-center justify-center bg-black/60 p-4 backdrop-blur-[2px]">
      <div className="w-full max-w-md space-y-4 rounded-xl border bg-background p-6 shadow-2xl">
        <div className="flex items-start gap-3">
          <Loader2 className="mt-0.5 size-5 shrink-0 animate-spin text-primary" />

          <div className="min-w-0">
            <h2 className="font-semibold">{t('map.render.title')}</h2>
            <p className="text-sm text-muted-foreground">{t('map.render.description')}</p>
          </div>
        </div>

        <div className="space-y-2">
          <div className="flex items-baseline justify-between gap-3 text-sm">
            <span>{t(`settings.render.phase.${progress.phase ?? 'starting'}`, progress.phase ?? '')}</span>
            <span className="tabular-nums text-muted-foreground">
              {percent}% · {elapsed(progress.startedAt ?? 0)}
            </span>
          </div>

          <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
            <div
              className="h-full bg-primary transition-[width] duration-300"
              style={{ width: `${percent}%` }}
            />
          </div>
        </div>

        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          <Figure
            label={t('settings.render.cells')}
            value={`${done.toLocaleString()} / ${total.toLocaleString()}`}
          />
          {/* The grid is 78 by 64, but the world is not a rectangle:
              the rest report themselves empty and cost nothing. */}
          <Figure
            label={t('settings.render.rendered')}
            value={`${(progress.cellsRendered ?? 0).toLocaleString()}${
              (progress.cellsEmpty ?? 0) > 0
                ? ` (${(progress.cellsEmpty ?? 0).toLocaleString()} ${t('map.render.emptyCells')})`
                : ''
            }`}
          />
          <Figure label={t('settings.render.tiles')} value={(progress.tilesUploaded ?? 0).toLocaleString()} />
          <Figure label={t('settings.render.uploaded')} value={size(progress.bytesUploaded ?? 0)} />
        </dl>

        {/* Shown from the first batch, not once an estimate exists: a
            run that reports nothing for its first minutes looks stuck. */}
        <dl className="grid grid-cols-3 gap-x-4 gap-y-2 rounded-md bg-muted/60 px-3 py-2 text-sm">
          <Figure label={t('map.render.batchTotal')} value={(progress.batchTotal ?? 0).toLocaleString()} />
          <Figure label={t('map.render.batchDone')} value={(progress.batchDone ?? 0).toLocaleString()} />
          <Figure label={t('map.render.batchPending')} value={(progress.batchPending ?? 0).toLocaleString()} />
        </dl>

        <dl className="grid grid-cols-3 gap-x-4 gap-y-2 rounded-md bg-muted/40 px-3 py-2 text-xs">
          {/* Estimated, not counted: how many tiles a world makes
              depends on what stands in each cell, so this is the rate
              so far carried across the cells still to come. */}
          <Figure
            label={t('map.render.totalTiles')}
            value={
              (progress.tilesEstimated ?? 0) > 0
                ? `≈ ${(progress.tilesEstimated ?? 0).toLocaleString()}`
                : '—'
            }
          />
          <Figure label={t('map.render.totalDone')} value={(progress.tilesUploaded ?? 0).toLocaleString()} />
          <Figure
            label={t('map.render.totalPending')}
            value={
              (progress.tilesEstimated ?? 0) > 0
                ? `≈ ${Math.max(
                    0,
                    (progress.tilesEstimated ?? 0) - (progress.tilesUploaded ?? 0),
                  ).toLocaleString()}`
                : '—'
            }
          />
        </dl>

        {(progress.tilesFailed ?? 0) > 0 && (
          <p className="flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-500">
            <TriangleAlert className="size-3.5 shrink-0" />
            {/* Cleared as soon as they arrive: a tile the store refused
                is retried on the next batch, so this is what is
                outstanding right now, not a tally of everything that
                ever failed. */}
            {t('map.render.failedNote', { count: progress.tilesFailed ?? 0 })}
          </p>
        )}

        {(progress.tilesSkipped ?? 0) > 0 && (
          <p className="text-xs text-muted-foreground">
            {t('map.render.skippedNote', { count: progress.tilesSkipped ?? 0 })}
          </p>
        )}

        {/* The tile name going past is how somebody tells a working
            render from a stuck one. */}
        <p className="truncate rounded-md bg-muted/60 px-2 py-1.5 font-mono text-xs text-muted-foreground">
          {progress.currentCell ? `${t('settings.render.cell')} ${progress.currentCell} · ` : ''}
          {progress.currentTile || '…'}
        </p>

        <div className="flex items-center justify-between gap-3">
          <p className="text-xs text-muted-foreground">{t('map.render.keepsGoing')}</p>

          {/* Asks rather than kills: the run stops at the next batch
              boundary, where the last upload is already verified. */}
          <div className="flex shrink-0 gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={holdRun.isPending || stopping}
              onClick={() => holdRun.mutate(paused)}
            >
              {paused ? <Play className="size-3.5" /> : <Pause className="size-3.5" />}
              {paused ? t('map.render.resume') : t('map.render.pause')}
            </Button>

            {/* The run ends at the next batch boundary, so the window
                stays and says so rather than closing on a promise. */}
            <Button
              type="button"
              variant="destructive"
              size="sm"
              onClick={() => {
                setStopped(true)
                stopRun.mutate()
              }}
            >
              <X className="size-3.5" />
              {t('map.render.stop')}
            </Button>
          </div>
        </div>
      </div>
    </div>
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
