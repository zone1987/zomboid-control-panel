/**
 * Everything the map needs to know about the world it draws.
 *
 * One place, because these numbers appear in the tile source, the
 * coordinate transform, the marker placement and the deep link, and
 * four copies of a constant drift apart.
 */


/**
 * One floor of the world, with the tiles that draw it.
 *
 * Zomboid numbers floors from the ground up: 0 is ground level, 1 to 7
 * are storeys above, negative ones are basements.
 */
export type MapLayer = {
  level: number
}

export type MapSource = {

  tileSize?: number

  /** Prefix every tile and .dzi path is resolved against. */
  root: string

  /**
   * How a world square maps onto the rendered image.
   *
   * pzmap2dzi writes these into map_info.json; the isometric numbers
   * differ from the top-down ones, which is why they live with the
   * source rather than in the transform.
   */
  geometry: MapGeometry

  layers: MapLayer[]
}

// Metadata from this immutable render's base/map_info.json and layer descriptors.
export const PROJECT_ZOMBOID_MAP: MapSource = {
  root: 'https://tiles.projectzomboidmap.com/maps/b42.20.2-r1/base',
  tileSize: 1024,
  geometry: {
    originX: 1036288,
    originY: -139296,
    squareSize: 128,
    scale: 1,
    floorHeight: 192,
    width: 2314368,
    height: 1019040,
    cellSize: 256,
  },
  layers: Array.from({ length: 47 }, (_, index) => {
    const level = index - 17
    return { level }
  }),
}

export function floorFor(source: MapSource, floor: number): number {
  if (source.layers.some(({ level }) => level === floor)) return floor
  return source.layers.find(({ level }) => level === 0)?.level ?? source.layers[0].level
}

export type MapGeometry = {
  /** Image pixel that world square 0,0 maps to. */
  originX: number
  originY: number
  /** Pixels per world square along one isometric axis. */
  squareSize: number
  /** Divides the result; pzmap2dzi calls this scale. */
  scale: number
  /** Vertical offset per floor, in pixels. Zero for a flat projection. */
  floorHeight: number
  /** Size of the fully rendered image at the deepest zoom. */
  width: number
  height: number
  /** Squares per cell, for reading pzmap2dzi's own numbers back. */
  cellSize: number
}

/**
 * The named places, where the game itself puts their names.
 *
 * Read from the game's own worldmap-annotations.lua: each town has an
 * addUntranslatedText("MapLabel_<name>", "text-town", x, y) entry, which
 * is the position the game draws that label at on its in-game map.
 *
 * Not map.info's zoomX/zoomY, which this list used to hold. Those are
 * the camera position of a start area -- a spawn point, not a town --
 * and they sit far enough off that Muldraugh's name landed in the woods
 * east of the town and every jump target missed.
 */
export const QUICK_TARGETS = [
  { id: 'muldraugh', x: 10754, y: 9926 },
  { id: 'westPoint', x: 11654, y: 6864 },
  { id: 'riverside', x: 6450, y: 5430 },
  { id: 'rosewood', x: 8159, y: 11661 },
  { id: 'marchRidge', x: 10130, y: 12801 },
  { id: 'louisville', x: 13077, y: 2238 },
  { id: 'valleyStation', x: 13447, y: 5278 },
  { id: 'echoCreek', x: 3589, y: 10952 },
  { id: 'brandenburg', x: 2056, y: 6070 },
  { id: 'irvington', x: 2427, y: 14185 },
  { id: 'ekron', x: 634, y: 9746 },
  { id: 'fallasLake', x: 7253, y: 8279 },
] as const satisfies readonly MapPlace[]

/**
 * A named place on the map.
 *
 * Every name is drawn the same size and at every zoom. The game varies
 * its own label scale, but transferred straight into CSS that made
 * Louisville tower over the map, and the user asked for one size.
 */
export type MapPlace = {
  id: string
  x: number
  y: number
}
