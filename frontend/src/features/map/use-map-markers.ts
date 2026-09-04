import { useEffect } from 'react'
import OpenSeadragon from 'openseadragon'

import { worldToViewport } from './coordinates'
import type { MapSource } from './map-config'
import type { MapPlayer, MapSafehouse, MapVehicle } from './map'
import type { LayerVisibility } from './layer-toggles'

type Options = {
  viewer: OpenSeadragon.Viewer | null
  ready: boolean
  source: MapSource
  floor: number
  players: MapPlayer[]
  safehouses: MapSafehouse[]
  vehicles: MapVehicle[]
  visible: LayerVisibility
  onPlayerClick: (player: MapPlayer) => void
}

/**
 * Puts players and safehouses on the map as overlays.
 *
 * Overlays rather than drawn tiles: OpenSeadragon keeps an overlay
 * pinned to its viewport coordinate through every pan and zoom, which
 * is exactly what a marker has to do, and they stay plain DOM so the
 * panel's own styling applies.
 *
 * Runs on every roster change -- three seconds -- and touches only the
 * overlays, never the viewer itself.
 */
export function useMapMarkers({
  viewer,
  ready,
  source,
  floor,
  players,
  safehouses,
  vehicles,
  visible,
  onPlayerClick,
}: Options): void {
  useEffect(() => {
    if (viewer === null || !ready) {
      return
    }

    const added: HTMLElement[] = []

    if (visible.safehouses) {
      for (const house of safehouses) {
        const element = document.createElement('div')
        element.className = 'pz-safehouse'
        element.title = house.title === '' ? house.owner : house.title

        const corner = worldToViewport({ x: house.x, y: house.y }, floor, source)
        const opposite = worldToViewport(
          { x: house.x + house.w, y: house.y + house.h },
          floor,
          source,
        )

        viewer.addOverlay({
          element,
          location: new OpenSeadragon.Rect(
            Math.min(corner.x, opposite.x),
            Math.min(corner.y, opposite.y),
            Math.abs(opposite.x - corner.x),
            Math.abs(opposite.y - corner.y),
          ),
        })

        added.push(element)
      }
    }

    for (const player of visible.players ? players : []) {
      const element = document.createElement('button')
      element.type = 'button'
      element.className = player.infected ? 'pz-player pz-player--infected' : 'pz-player'
      element.title = player.username
      element.setAttribute('aria-label', player.username)

      const label = document.createElement('span')
      label.className = 'pz-player__name'
      label.textContent = player.username
      element.append(label)

      element.addEventListener('click', () => onPlayerClick(player))

      const point = worldToViewport({ x: player.x, y: player.y }, floor, source)

      viewer.addOverlay({
        element,
        location: new OpenSeadragon.Point(point.x, point.y),
        placement: OpenSeadragon.Placement.CENTER,
        checkResize: false,
      })

      added.push(element)
    }

    for (const vehicle of visible.vehicles ? vehicles : []) {
      const element = document.createElement('div')
      element.className = vehicle.engineRunning ? 'pz-vehicle pz-vehicle--running' : 'pz-vehicle'
      element.title = `${vehicle.script}${vehicle.fuel === null ? '' : ` · ${Math.round(vehicle.fuel)}%`}`

      const point = worldToViewport({ x: vehicle.x, y: vehicle.y }, floor, source)

      viewer.addOverlay({
        element,
        location: new OpenSeadragon.Point(point.x, point.y),
        placement: OpenSeadragon.Placement.CENTER,
        checkResize: false,
      })

      added.push(element)
    }

    return () => {
      for (const element of added) {
        viewer.removeOverlay(element)
      }
    }
  }, [viewer, ready, source, floor, players, safehouses, vehicles, visible, onPlayerClick])
}
