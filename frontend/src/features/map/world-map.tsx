import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'

import type { WorldPoint } from './coordinates'
import type { MapSource } from './map-config'
import type { MapPlayer, MapSafehouse, MapVehicle } from './map'
import { LayerToggles, type LayerVisibility, type MapLayerId } from './layer-toggles'
import { encodeViewState, isSameView, type MapViewState } from './map-url-state'
import { useMapViewer } from './use-map-viewer'
import { useMapMarkers } from './use-map-markers'
import { FloorControl } from './floor-control'
import { MapControls } from './map-controls'

type Props = {
  source: MapSource
  players: MapPlayer[]
  safehouses: MapSafehouse[]
  vehicles: MapVehicle[]
  visible: LayerVisibility
  onLayerChange: (layer: MapLayerId, shown: boolean) => void
  initial: MapViewState | null
  onContextMenu: (point: WorldPoint) => void
  onPlayerClick: (player: MapPlayer) => void
  /** Set by the page to move the view from outside -- search, places. */
  onReady: (goTo: (point: WorldPoint, zoom?: number) => void) => void
}

/** How long panning has to settle before the URL is rewritten. */
const URL_DEBOUNCE_MS = 400

export function WorldMap({
  source,
  players,
  safehouses,
  vehicles,
  visible,
  onLayerChange,
  initial,
  onContextMenu,
  onPlayerClick,
  onReady,
}: Props) {
  const { t } = useTranslation()
  const frame = useRef<HTMLDivElement>(null)
  const [fullscreen, setFullscreen] = useState(false)
  const [surface, setSurface] = useState<HTMLElement | null>(null)

  // The last view written to the URL, so an unchanged view writes nothing.
  const written = useRef<MapViewState | null>(initial)
  const timer = useRef<number | null>(null)

  const onViewChanged = useCallback((state: MapViewState) => {
    if (timer.current !== null) {
      window.clearTimeout(timer.current)
    }

    timer.current = window.setTimeout(() => {
      if (isSameView(written.current, state)) {
        return
      }

      written.current = state
      // replaceState, not push: panning should not fill the back button
      // with every intermediate position.
      window.history.replaceState(null, '', `#${encodeViewState(state)}`)
    }, URL_DEBOUNCE_MS)
  }, [])

  const viewer = useMapViewer({ source, initial, onViewChanged, onContextMenu })

  useMapMarkers({
    viewer: viewer.viewer,
    ready: viewer.ready,
    source,
    floor: viewer.floor,
    players,
    safehouses,
    vehicles,
    visible,
    onPlayerClick,
  })

  useEffect(() => {
    if (viewer.ready) {
      onReady(viewer.goTo)
    }
  }, [viewer.ready, viewer.goTo, onReady])

  useEffect(
    () => () => {
      if (timer.current !== null) {
        window.clearTimeout(timer.current)
      }
    },
    [],
  )

  useEffect(() => {
    const onChange = () => setFullscreen(document.fullscreenElement === frame.current)

    document.addEventListener('fullscreenchange', onChange)

    return () => document.removeEventListener('fullscreenchange', onChange)
  }, [])

  const toggleFullscreen = () => {
    if (document.fullscreenElement === frame.current) {
      void document.exitFullscreen()
    } else {
      void frame.current?.requestFullscreen()
    }
  }

  const copyLink = () => {
    const state = {
      x: viewer.centre.x,
      y: viewer.centre.y,
      zoom: viewer.viewer?.viewport.getZoom() ?? 1,
      floor: viewer.floor,
    }

    void navigator.clipboard?.writeText(
      `${window.location.origin}${window.location.pathname}#${encodeViewState(state)}`,
    )
  }

  const levels = source.layers.map((layer) => layer.level)

  return (
    <div ref={frame} className="relative size-full overflow-hidden rounded-md bg-muted/30">
      <div
        ref={(node) => {
          viewer.containerRef(node)
          setSurface(node)
        }}
        className="size-full"
      />

      <MapControls
        centre={viewer.centre}
        pointer={viewer.pointer}
        fullscreen={fullscreen}
        onZoomIn={() => viewer.zoomBy(1.5)}
        onZoomOut={() => viewer.zoomBy(1 / 1.5)}
        onReset={viewer.reset}
        onToggleFullscreen={toggleFullscreen}
        onCopyLink={copyLink}
      />

      <LayerToggles
        visible={visible}
        counts={{
          players: players.length,
          safehouses: safehouses.length,
          vehicles: vehicles.length,
        }}
        onChange={onLayerChange}
      />

      <FloorControl
        levels={levels}
        floor={viewer.floor}
        onChange={viewer.setFloor}
        target={surface}
      />

      {viewer.loadFailed && (
        <div role="status" className="absolute bottom-24 left-3 right-3 z-10 rounded-md border bg-background/95 p-3 text-sm sm:right-auto sm:max-w-sm">
          {t('map.external.unavailable')}
          <a href="https://projectzomboidmap.com/" target="_blank" rel="noopener noreferrer" className="ml-1 text-primary underline">
            projectzomboidmap.com
          </a>
        </div>
      )}
    </div>
  )
}
