import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronDown, ChevronUp, Loader2, Minus, Pause, Play, TriangleAlert, X } from 'lucide-react'
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

import { overallPercent, remainingSeconds } from './render-estimate'
import { RenderFigures } from './render-figures'
import { duration } from './render-format'
import { RenderTimeline } from './render-timeline'

type WindowState = 'open' | 'collapsed' | 'minimised'

const STATE_KEY = 'zc:render-window'

function storedState(): WindowState {
  try {
    const value = localStorage.getItem(STATE_KEY)

    return value === 'collapsed' || value === 'minimised' || value === 'open' ? value : 'collapsed'
  } catch {
    // A browser refusing storage is not a reason to show nothing.
    return 'collapsed'
  }
}

/**
 * What the render is doing, over the map it is producing.
 *
 * Bottom centre, because everything else is taken: zoom and search top
 * left, players top right, layers and floors on the sides, coordinates
 * bottom left, places bottom right. Measured in a browser rather than
 * assumed -- the free strip is 906 px at 1440 and 746 at 1280.
 *
 * It grows upward when opened, so the click target stays under the
 * cursor and neither bottom corner is ever covered.
 */
export function RenderOverlay({ hasRender = true }: { hasRender?: boolean }) {
  const { t } = useTranslation()
  const [progress, setProgress] = useState<RenderProgress>({ state: 'idle' })
  const [windowState, setWindowState] = useState<WindowState>(storedState)

  // Closed on the click, not on the answer: the run is killed server
  // side, and a window that lingers while uploads cost money reads as a
  // button that did nothing.
  const [stopped, setStopped] = useState(false)

  const show = (next: WindowState) => {
    setWindowState(next)

    try {
      localStorage.setItem(STATE_KEY, next)
    } catch {
      // Preference lost, window still works.
    }
  }

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

  const failed = progress.state === 'failed'

  // A failure behind a collapsed window is the one outcome this must
  // not produce, so it opens itself rather than waiting to be asked.
  useEffect(() => {
    if (failed) {
      setWindowState('open')
    }
  }, [failed])

  if (stopped) {
    return null
  }

  // Nothing rendered and nothing running: the map behind this is black,
  // so the box says what to do rather than leaving an empty page.
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
  const percent = overallPercent(progress)
  const remaining = remainingSeconds(progress)
  const phase = t(`settings.render.phase.${progress.phase ?? 'starting'}`, progress.phase ?? '')

  if (windowState === 'minimised') {
    return (
      <div className="pointer-events-none absolute inset-x-0 bottom-3 z-20 flex justify-center">
        <button
          type="button"
          className="pointer-events-auto flex size-10 items-center justify-center rounded-full border border-border/60 bg-background/90 shadow-lg backdrop-blur hover:bg-accent"
          title={`${phase} · ${percent}%`}
          aria-label={`${phase} · ${percent}%`}
          onClick={() => show('collapsed')}
        >
          <Ring percent={percent} />
        </button>
      </div>
    )
  }

  const open = windowState === 'open'

  return (
    <div className="pointer-events-none absolute inset-x-0 bottom-3 z-20 flex justify-center px-4">
      <div
        className={`pointer-events-auto w-full max-w-[min(34rem,100%)] overflow-hidden rounded-lg border bg-background/95 shadow-2xl backdrop-blur ${
          failed ? 'border-destructive' : 'border-border/60'
        }`}
      >
        {open && (
          <div className="max-h-[60vh] space-y-3 overflow-y-auto border-b border-border/60 p-3">
            <RenderTimeline progress={progress} />
            <RenderFigures progress={progress} />

            <div className="flex items-center justify-between gap-3">
              <p className="text-[0.65rem] text-muted-foreground">{t('map.render.keepsGoing')}</p>

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
        )}

        <div className="flex items-center gap-2 px-3 py-2 text-xs">
          {failed ? (
            <TriangleAlert className="size-4 shrink-0 text-destructive" />
          ) : (
            <Loader2 className="size-4 shrink-0 animate-spin text-primary" />
          )}

          <button
            type="button"
            className="flex min-w-0 flex-1 items-center gap-1.5 text-left hover:text-foreground"
            aria-expanded={open}
            onClick={() => show(open ? 'collapsed' : 'open')}
          >
            <span className="truncate font-medium">{phase}</span>
            <span className="shrink-0 tabular-nums text-muted-foreground">· {percent} %</span>
            {remaining !== null && (
              <span className="hidden shrink-0 tabular-nums text-muted-foreground sm:inline">
                · {t('map.render.remaining', { time: duration(remaining) })}
              </span>
            )}
          </button>

          <button
            type="button"
            className="shrink-0 rounded p-1 text-muted-foreground hover:bg-accent hover:text-accent-foreground"
            title={t('map.render.minimise')}
            aria-label={t('map.render.minimise')}
            onClick={() => show('minimised')}
          >
            <Minus className="size-3.5" />
          </button>

          <button
            type="button"
            className="shrink-0 rounded p-1 text-muted-foreground hover:bg-accent hover:text-accent-foreground"
            title={open ? t('map.render.collapse') : t('map.render.expand')}
            aria-label={open ? t('map.render.collapse') : t('map.render.expand')}
            onClick={() => show(open ? 'collapsed' : 'open')}
          >
            {open ? <ChevronDown className="size-3.5" /> : <ChevronUp className="size-3.5" />}
          </button>
        </div>
      </div>
    </div>
  )
}

/** Percent as a ring, for the minimised state. */
function Ring({ percent }: { percent: number }) {
  const radius = 8
  const circumference = 2 * Math.PI * radius

  return (
    <svg viewBox="0 0 20 20" className="size-5 -rotate-90">
      <circle cx="10" cy="10" r={radius} fill="none" stroke="currentColor" strokeWidth="3" className="text-muted" />
      <circle
        cx="10"
        cy="10"
        r={radius}
        fill="none"
        stroke="currentColor"
        strokeWidth="3"
        strokeLinecap="round"
        className="text-primary"
        strokeDasharray={circumference}
        strokeDashoffset={circumference * (1 - percent / 100)}
      />
    </svg>
  )
}
