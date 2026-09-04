import { useEffect, useRef } from 'react'
import L from 'leaflet'

import { levelOfLeafletZoom, TILE_URL, type MapPlayer, type MapSafehouse, type MapStatus } from './map'

type Props = {
  status: MapStatus
  players: MapPlayer[]
  safehouses: MapSafehouse[]
  onContextMenu: (point: { x: number; y: number }) => void
  focus: { x: number; y: number } | null
}

/**
 * The game's own map, drawn with Leaflet on a plain pixel grid.
 *
 * CRS.Simple maps one world square onto one pixel at the deepest zoom,
 * which the pyramid already matches, so a player's position needs no
 * projection -- only the y flip Leaflet's coordinate order asks for.
 */
export function WorldMap({ status, players, safehouses, onContextMenu, focus }: Props) {
  const container = useRef<HTMLDivElement>(null)
  const map = useRef<L.Map | null>(null)
  const playerLayer = useRef<L.LayerGroup | null>(null)
  const houseLayer = useRef<L.LayerGroup | null>(null)
  const handler = useRef(onContextMenu)

  handler.current = onContextMenu

  useEffect(() => {
    if (container.current === null || map.current !== null) {
      return
    }

    const { width, height } = status.world
    const maxZoom = status.maxLevel

    const instance = L.map(container.current, {
      crs: worldCrs(maxZoom),
      minZoom: 0,
      maxZoom,
      zoomControl: false,
      attributionControl: false,
      // Panning past the world's edge only ever shows grey.
      maxBounds: L.latLngBounds(toLatLng(0, 0), toLatLng(width, height)),
      maxBoundsViscosity: 1,
    })

    new PyramidLayer(maxZoom, {
      tileSize: status.tileSize,
      minZoom: 0,
      maxZoom,
      noWrap: true,
      bounds: L.latLngBounds(toLatLng(0, 0), toLatLng(width, height)),
    }).addTo(instance)

    instance.setView(toLatLng(10778, 9770), Math.max(0, maxZoom - 2))

    instance.on('contextmenu', (event: L.LeafletMouseEvent) => {
      handler.current({
        x: Math.round(event.latlng.lng),
        y: Math.round(event.latlng.lat),
      })
    })

    playerLayer.current = L.layerGroup().addTo(instance)
    houseLayer.current = L.layerGroup().addTo(instance)
    map.current = instance

    return () => {
      instance.remove()
      map.current = null
    }
  }, [status])

  useEffect(() => {
    const layer = playerLayer.current

    if (layer === null) {
      return
    }

    layer.clearLayers()

    for (const player of players) {
      L.circleMarker(toLatLng(player.x, player.y), {
        radius: 6,
        weight: 2,
        color: '#ffffff',
        fillColor: player.infected ? '#dc2626' : '#10b981',
        fillOpacity: 1,
      })
        .bindTooltip(player.username, { direction: 'top', offset: [0, -8] })
        .addTo(layer)
    }
  }, [players, status])

  useEffect(() => {
    const layer = houseLayer.current

    if (layer === null) {
      return
    }

    layer.clearLayers()

    for (const house of safehouses) {
      L.rectangle(
        L.latLngBounds(
          toLatLng(house.x, house.y),
          toLatLng(house.x + house.w, house.y + house.h),
        ),
        { color: '#3b82f6', weight: 2, fillOpacity: 0.2 },
      )
        .bindTooltip(house.title === '' ? house.owner : house.title, { direction: 'top' })
        .addTo(layer)
    }
  }, [safehouses, status])

  useEffect(() => {
    if (focus !== null && map.current !== null) {
      map.current.setView(toLatLng(focus.x, focus.y), status.maxLevel - 1)
    }
  }, [focus, status])

  return <div ref={container} className="h-full w-full rounded-md" />
}

/**
 * Leaflet's zoom 0 is the whole world; the pyramid's level 0 is the most
 * detailed. The tile URL wants the level, so it is derived per tile
 * rather than by shifting Leaflet's own zoom.
 */
class PyramidLayer extends L.TileLayer {
  private readonly maxLevel: number

  constructor(maxLevel: number, options: L.TileLayerOptions) {
    super(TILE_URL, options)
    this.maxLevel = maxLevel
  }

  override getTileUrl(coords: L.Coords): string {
    return TILE_URL.replace('{z}', String(levelOfLeafletZoom(coords.z, this.maxLevel)))
      .replace('{x}', String(coords.x))
      .replace('{y}', String(coords.y))
  }
}

/**
 * A coordinate system matching the tiles rather than the globe.
 *
 * Two things have to line up. CRS.Simple puts one map unit on one pixel
 * at zoom 0, but the pyramid's finest level is Leaflet's *highest* zoom,
 * so the scale is shifted by maxZoom. And Simple's y axis runs upward
 * while tile rows run downward, so the transformation keeps y positive
 * instead of mirroring it -- which is what left every row negative.
 */
function worldCrs(maxZoom: number): L.CRS {
  return L.extend({}, L.CRS.Simple, {
    transformation: new L.Transformation(1, 0, 1, 0),
    scale: (zoom: number) => 2 ** (zoom - maxZoom),
    zoom: (scale: number) => Math.log(scale) / Math.LN2 + maxZoom,
  }) as L.CRS
}

/** With y running downward, a world square is its own map point. */
function toLatLng(x: number, y: number): L.LatLngExpression {
  return [y, x]
}

export { levelOfLeafletZoom }
