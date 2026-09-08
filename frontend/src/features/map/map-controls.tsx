import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Check, Copy, Expand, Home, Minus, Plus, Shrink } from 'lucide-react'

import type { WorldPoint } from './coordinates'

type Props = {
  centre: WorldPoint
  pointer: WorldPoint | null
  fullscreen: boolean
  onZoomIn: () => void
  onZoomOut: () => void
  onReset: () => void
  onToggleFullscreen: () => void
  onCopyLink: () => void
}

/**
 * The buttons over the map: zoom, reset, fullscreen, and a readout of
 * where the view is.
 *
 * The readout shows the pointer while the mouse is over the map and the
 * centre otherwise, which is what an operator reads out to somebody
 * else -- "meet me at 10778, 9770".
 */
export function MapControls({
  centre,
  pointer,
  fullscreen,
  onZoomIn,
  onZoomOut,
  onReset,
  onToggleFullscreen,
  onCopyLink,
}: Props) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)

  const shown = pointer ?? centre

  const copy = () => {
    onCopyLink()
    setCopied(true)
    window.setTimeout(() => setCopied(false), 1600)
  }

  return (
    <>
      <div className="pointer-events-auto absolute left-3 top-3 z-10 flex flex-col gap-1 rounded-md border border-border/60 bg-background/85 p-1 shadow-lg backdrop-blur">
        <Control label={t('map.zoomIn')} onClick={onZoomIn}>
          <Plus className="size-4" />
        </Control>
        <Control label={t('map.zoomOut')} onClick={onZoomOut}>
          <Minus className="size-4" />
        </Control>
        <Control label={t('map.resetView')} onClick={onReset}>
          <Home className="size-4" />
        </Control>
        <Control
          label={fullscreen ? t('map.leaveFullscreen') : t('map.fullscreen')}
          onClick={onToggleFullscreen}
        >
          {fullscreen ? <Shrink className="size-4" /> : <Expand className="size-4" />}
        </Control>
      </div>

      {/* 8rem, not 6rem: the places button opposite is 87px plus its own
          0.75rem edge, and 6rem left it overlapping by 15px. */}
      <div className="absolute bottom-3 left-3 z-10 flex max-w-[calc(100%-8rem)] flex-wrap items-center gap-2">
        <button
          type="button"
          className="pointer-events-auto flex shrink-0 items-center gap-2 rounded-md border border-border/60 bg-background/85 px-2.5 py-1.5 text-xs tabular-nums shadow-lg backdrop-blur hover:bg-accent hover:text-accent-foreground"
          title={t('map.copyLink')}
          aria-label={t('map.copyLink')}
          onClick={copy}
        >
          <span>
            {Math.round(shown.x)}, {Math.round(shown.y)}
          </span>
          {copied ? (
            <Check className="size-3.5 text-emerald-500" />
          ) : (
            <Copy className="size-3.5 text-muted-foreground" />
          )}
        </button>
        <a
          href="https://projectzomboidmap.com/"
          target="_blank"
          rel="noopener noreferrer"
          className="rounded bg-background/85 px-2 py-1 text-[10px] text-muted-foreground hover:text-foreground"
          title={t('map.external.description')}
        >
          © The Indie Stone · projectzomboidmap.com · B42.20.2
        </a>
      </div>
    </>
  )
}

function Control({
  label,
  onClick,
  children,
}: {
  label: string
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      className="flex size-7 items-center justify-center rounded-sm text-muted-foreground hover:bg-accent hover:text-accent-foreground"
      title={label}
      aria-label={label}
      onClick={onClick}
    >
      {children}
    </button>
  )
}
