import { useCallback, useEffect, useRef, useState } from 'react'
import OpenSeadragon from 'openseadragon'

import { viewportToWorld, worldToViewport, type WorldPoint } from './coordinates'
import type { MapSource } from './map-config'

// A tile that has not been drawn yet is the normal state during a
// render, and OpenSeadragon logs an error for every one of them.
const seadragonConsole = (OpenSeadragon as unknown as { console: Console }).console
const originalError = seadragonConsole.error.bind(seadragonConsole)

seadragonConsole.error = (...args: unknown[]) => {
  if (typeof args[0] === 'string' && args[0].startsWith('Tile %s failed to load')) {
    return
  }

  originalError(...args)
}
import { fitZoom, tileSourceFor } from './tile-source'
import type { MapViewState } from './map-url-state'

type Options = {
  source: MapSource
  initial: MapViewState | null
  onViewChanged: (state: MapViewState) => void
  onContextMenu: (point: WorldPoint) => void
}

export type MapViewer = {
  containerRef: (element: HTMLDivElement | null) => void
  ready: boolean
  floor: number
  setFloor: (floor: number) => void
  centre: WorldPoint
  pointer: WorldPoint | null
  goTo: (point: WorldPoint, zoom?: number) => void
  zoomBy: (factor: number) => void
  reset: () => void
  viewer: OpenSeadragon.Viewer | null
}

/**
 * Owns the OpenSeadragon instance.
 *
 * Kept out of the component because a viewer must be built exactly once:
 * React re-renders whenever a player moves, and rebuilding on each of
 * those would throw away the operator's position several times a
 * second.
 */
export function useMapViewer({ source, initial, onViewChanged, onContextMenu }: Options): MapViewer {
  const [ready, setReady] = useState(false)
  const [floor, setFloorState] = useState(initial?.floor ?? 0)
  const [centre, setCentre] = useState<WorldPoint>({
    x: initial?.x ?? 10778,
    y: initial?.y ?? 9770,
  })
  const [pointer, setPointer] = useState<WorldPoint | null>(null)

  const viewer = useRef<OpenSeadragon.Viewer | null>(null)
  const element = useRef<HTMLDivElement | null>(null)
  const reportFloor = useRef<(level: number) => void>(() => undefined)

  // Held in refs so the handlers registered once still see the current
  // values, without re-registering them on every render.
  const state = useRef({ source, floor, onViewChanged, onContextMenu })
  state.current = { source, floor, onViewChanged, onContextMenu }

  const containerRef = useCallback((node: HTMLDivElement | null) => {
    element.current = node
  }, [])

  useEffect(() => {
    const node = element.current

    if (node === null || viewer.current !== null) {
      return
    }

    const instance = OpenSeadragon({
      element: node,
      tileSources: tileSourceFor(source, initial?.floor ?? 0),
      showNavigationControl: false,
      // The panel draws its own, in the panel's own style.
      showNavigator: false,
      animationTime: 0.4,
      springStiffness: 8,
      // The game's map is one pixel per world square, so a tile pixel is
      // tiny on screen. Letting it magnify well past 1:1 is what makes
      // close inspection possible at all.
      maxZoomPixelRatio: 16,
      minZoomLevel: 0.2,
      visibilityRatio: 0.6,
      constrainDuringPan: true,
      gestureSettingsMouse: { clickToZoom: false, dblClickToZoom: true },
      gestureSettingsTouch: { pinchToZoom: true, flickEnabled: true },
    })

    viewer.current = instance

    const report = () => {
      const { source: current, floor: level, onViewChanged: notify } = state.current
      const middle = instance.viewport.getCenter()
      const world = viewportToWorld({ x: middle.x, y: middle.y }, level, current)

      setCentre(world)
      notify({ x: world.x, y: world.y, zoom: instance.viewport.getZoom(), floor: level })
    }

    /**
     * Places the starting view.
     *
     * On 'open' the viewport exists but OpenSeadragon has not finished
     * its own home animation, and whatever is set there is overwritten
     * a frame later. Waiting for the first drawn frame is what makes a
     * deep link land where it says.
     */
    instance.addOnceHandler('open', () => {
      instance.addOnceHandler('animation-finish', () => {
        const start = initial ?? { x: 10778, y: 9770, zoom: 2, floor: 0 }
        const point = worldToViewport({ x: start.x, y: start.y }, start.floor, source)

        instance.viewport.zoomTo(start.zoom, undefined, true)
        instance.viewport.panTo(new OpenSeadragon.Point(point.x, point.y), true)
        instance.viewport.applyConstraints(true)

        // The centre moved without an event of its own, so the
        // readout and the URL are told directly.
        report()
        setReady(true)
      })

      // Nudges the viewport so animation-finish fires even when nothing
      // else would move it.
      instance.viewport.zoomBy(1.0001)
    })

    /** Tells the URL about a floor change, which moves nothing else. */
    reportFloor.current = (level: number) => {
      const { source: current, onViewChanged: notify } = state.current
      const middle = instance.viewport.getCenter()
      const world = viewportToWorld({ x: middle.x, y: middle.y }, level, current)

      notify({ x: world.x, y: world.y, zoom: instance.viewport.getZoom(), floor: level })
    }

    instance.addHandler('animation-finish', report)
    instance.addHandler('zoom', report)

    // A tile that is not there yet is the normal state during a render,
    // not a fault. Registering a handler keeps OpenSeadragon from
    // logging every one of them to the console.
    instance.addHandler('tile-load-failed', () => undefined)

    /**
     * A descriptor that is not there yet is the same thing, one level up.
     *
     * Before the first batch reaches the store there is no layer0.dzi,
     * and OpenSeadragon writes "Unable to open [object Object]: HTTP
     * 404" across the middle of the map in its own styling. The render
     * window already says what is happening, so this is noise on top of
     * an explanation -- and it stays on screen after the tiles arrive.
     */
    instance.addHandler('open-failed', () => {
      // Its message element is added to the container on failure and
      // never removed by the viewer itself.
      node.querySelectorAll('.openseadragon-message').forEach((message) => message.remove())
    })

    // Tracks the pointer so the readout can show where the mouse is.
    const tracker = new OpenSeadragon.MouseTracker({
      element: node,
      moveHandler: (event) => {
        const { source: current, floor: level } = state.current
        const point = instance.viewport.pointFromPixel(event.position as OpenSeadragon.Point)

        setPointer(viewportToWorld({ x: point.x, y: point.y }, level, current))
      },
      leaveHandler: () => setPointer(null),
    })

    /**
     * The right click, taken from the browser rather than from
     * OpenSeadragon.
     *
     * Its MouseTracker claims the button before nonPrimaryPressHandler
     * ever sees a real click -- verified in the browser -- so the
     * contextmenu event is both the reliable signal and the one that
     * has to be suppressed anyway.
     */
    const onContext = (event: MouseEvent) => {
      event.preventDefault()

      const { source: current, floor: level, onContextMenu: menu } = state.current
      const box = node.getBoundingClientRect()
      const pixel = new OpenSeadragon.Point(event.clientX - box.left, event.clientY - box.top)
      const point = instance.viewport.pointFromPixel(pixel)
      const world = viewportToWorld({ x: point.x, y: point.y }, level, current)

      menu({ x: Math.round(world.x), y: Math.round(world.y) })
    }

    node.addEventListener('contextmenu', onContext)

    return () => {
      node.removeEventListener('contextmenu', onContext)
      tracker.destroy()
      instance.destroy()
      viewer.current = null
      setReady(false)
    }
    // Built once. A change of source rebuilds it, which is correct:
    // switching between the game's map and an isometric render is a
    // different image, not a different view of the same one.
  }, [source])

  /**
   * Swaps the tiles under the current view.
   *
   * Centre and zoom are read before the swap and restored after, so
   * changing floor keeps the operator where they were looking.
   */
  const setFloor = useCallback(
    (next: number) => {
      const instance = viewer.current

      if (instance === null || next === state.current.floor) {
        return
      }

      const { source: current } = state.current


      const world = viewportToWorld(
        { x: instance.viewport.getCenter().x, y: instance.viewport.getCenter().y },
        state.current.floor,
        current,
      )
      const zoom = instance.viewport.getZoom()

      setFloorState(next)
      reportFloor.current(next)

      instance.addOnceHandler('open', () => {
        const point = worldToViewport(world, next, current)
        instance.viewport.panTo(new OpenSeadragon.Point(point.x, point.y), true)
        instance.viewport.zoomTo(zoom, undefined, true)
      })

      // open() types its argument more narrowly than tileSources does,
      // though it accepts the same values.
      instance.open(tileSourceFor(current, next) as Parameters<typeof instance.open>[0])
    },
    [],
  )

  const goTo = useCallback((point: WorldPoint, zoom?: number) => {
    const instance = viewer.current

    if (instance === null) {
      return
    }

    const target = worldToViewport(point, state.current.floor, state.current.source)
    instance.viewport.panTo(new OpenSeadragon.Point(target.x, target.y))

    if (zoom !== undefined) {
      instance.viewport.zoomTo(zoom)
    }
  }, [])

  const zoomBy = useCallback((factor: number) => {
    viewer.current?.viewport.zoomBy(factor)
    viewer.current?.viewport.applyConstraints()
  }, [])

  const reset = useCallback(() => {
    const instance = viewer.current

    if (instance === null || element.current === null) {
      return
    }

    const aspect = element.current.clientWidth / element.current.clientHeight
    instance.viewport.goHome()
    instance.viewport.zoomTo(fitZoom(state.current.source, aspect))
  }, [])

  return {
    containerRef,
    ready,
    floor,
    setFloor,
    centre,
    pointer,
    goTo,
    zoomBy,
    reset,
    viewer: viewer.current,
  }
}
