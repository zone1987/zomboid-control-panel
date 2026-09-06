import { useEffect } from 'react'
import OpenSeadragon from 'openseadragon'

import { worldToViewport } from './coordinates'
import { QUICK_TARGETS, type MapSource } from './map-config'
import type { MapPlayer, MapSafehouse, MapVehicle } from './map'
import type { LayerVisibility } from './layer-toggles'
import {
  describeVehicle,
  vehicleClass,
  vehicleImage,
  vehicleShape,
  vehicleSize,
} from './vehicle-marker'
import type { VehicleRenderer } from './vehicle-renderer'

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
  /** Names the places, translated by the caller. */
  placeName: (id: string) => string
  /** Names a vehicle from its script name, translated by the caller. */
  vehicleName: (script: string) => string
  /** Draws vehicles from the game's own models; null before it is ready. */
  vehicles3d: VehicleRenderer | null
}

/**
 * A length in world squares, as a fraction of the image width, which is
 * the unit OpenSeadragon measures its viewport in.
 *
 * One square along a world axis moves the image point by
 * (squareSize/2, squareSize/4) -- so it spans the hypotenuse of those,
 * about 71.6 pixels for a 128 square, not the 64 of the horizontal
 * component alone. Measuring only that component drew every vehicle at
 * 89 % of its length; taking half the square size drew it at 50 %,
 * which is what a game screenshot beside the panel showed.
 */
function squaresToViewport(squares: number, source: MapSource): number {
  const { squareSize, width } = source.geometry
  const perSquare = Math.hypot(squareSize * 0.5, squareSize * 0.25)

  return (squares * perSquare) / width
}

/**
 * Puts players, safehouses, vehicles and place names on the map as overlays.
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
  placeName,
  vehicleName,
  vehicles3d,
}: Options): void {
  useEffect(() => {
    if (viewer === null || !ready) {
      return
    }

    const added: HTMLElement[] = []

    /**
     * The town names.
     *
     * Pinned to floor 0 rather than the selected one: a name belongs to
     * the place on the ground, and following the floor offset upwards
     * would drift it away from the town it labels.
     *
     * Every town is drawn at every zoom, because the game's own labels
     * all carry setMinZoom(0) -- holding the smaller ones back until the
     * view is close enough was this panel's invention, not the game's.
     */
    for (const place of visible.places ? QUICK_TARGETS : []) {
      const element = document.createElement('div')
      element.className = 'pz-place'
      element.textContent = placeName(place.id)

      const point = worldToViewport({ x: place.x, y: place.y }, 0, source)

      viewer.addOverlay({
        element,
        location: new OpenSeadragon.Point(point.x, point.y),
        placement: OpenSeadragon.Placement.CENTER,
        checkResize: false,
      })

      added.push(element)
    }

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
      const artwork = vehicles3d?.artwork(vehicle.script) ?? null

      element.className = vehicleClass(vehicle)
      element.title = describeVehicle(vehicle, vehicleName)
      // The drawn shape first, so a marker is never empty while its
      // model loads; replaced by the real body once rendered.
      element.append(vehicleShape(vehicle, artwork))

      void vehicles3d?.draw(vehicle).then((made) => {
        if (made === null || !element.isConnected) {
          return
        }

        element.replaceChildren(vehicleImage(made))
      })

      // Placed as a rectangle in map coordinates rather than at a
      // point, so the vehicle grows and shrinks with the view the way
      // a building does.
      //
      // The box is square and sized to the vehicle's own length: the
      // render frames the body to exactly that extent, whichever way it
      // is turned, so the two line up without padding on either side.
      const { length, width } = vehicleSize(artwork)
      const box = squaresToViewport(Math.hypot(length, width), source)
      // Pinned to the ground, like the place names: a vehicle sits on
      // floor 0, and following the selected floor's offset would drift
      // it away from the road it stands on.
      const centre = worldToViewport({ x: vehicle.x, y: vehicle.y }, 0, source)

      viewer.addOverlay({
        element,
        location: new OpenSeadragon.Rect(
          centre.x - box / 2,
          centre.y - box / 2,
          box,
          box,
        ),
        checkResize: false,
      })

      added.push(element)
    }

    return () => {
      for (const element of added) {
        viewer.removeOverlay(element)
      }
    }
  }, [
    viewer,
    ready,
    source,
    floor,
    players,
    safehouses,
    vehicles,
    visible,
    onPlayerClick,
    placeName,
    vehicleName,
    vehicles3d,
  ])
}
