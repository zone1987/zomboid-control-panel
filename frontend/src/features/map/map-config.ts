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
 * Places worth jumping to.
 *
 * Taken from the game's own map.info files -- every start area records
 * a zoomX and zoomY, which is where the game centres when it shows that
 * town. More accurate than any list written by hand.
 *
 * Louisville has no start area of its own, so its coordinate is the
 * centre of the city rather than a value the game supplies.
 */
export const QUICK_TARGETS = [
  { id: 'muldraugh', x: 11181, y: 9725 },
  { id: 'westPoint', x: 11581, y: 6916 },
  { id: 'riverside', x: 6300, y: 5668 },
  { id: 'rosewood', x: 8446, y: 11556 },
  { id: 'marchRidge', x: 9921, y: 12603 },
  { id: 'louisville', x: 12800, y: 2000 },
  { id: 'valleyStation', x: 13056, y: 6031 },
  { id: 'echoCreek', x: 4235, y: 11069 },
  { id: 'brandenburg', x: 2314, y: 6253 },
  { id: 'irvington', x: 2729, y: 13797 },
  { id: 'ekron', x: 1020, y: 9838 },
  { id: 'fallasLake', x: 7348, y: 8371 },
] as const
