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
  /** Where the .dzi for this floor lives, relative to the tile root. */
  dzi: string
}

/**
 * pzmap2dzi writes layer 0 as jpg and every other floor as webp, because
 * the floors above have to be transparent to stack. Reading it wrong
 * gives a pyramid of 404s.
 */
export function tileExtensionFor(level: number): 'jpg' | 'webp' {
  return level === 0 ? 'jpg' : 'webp'
}

export type MapSource = {

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
 * An isometric render, as pzmap2dzi produces it.
 *
 * Not shipped: nobody distributes these tiles, and the game does not
 * contain them. An operator generates them from their own installation
 * -- see docs/superpowers/briefs/08-isometric-map.md -- and the panel
 * then serves them from its own storage.
 *
 * The geometry below is the shape pzmap2dzi writes into map_info.json
 * for build 42. The values are filled in from that file when tiles are
 * imported, so they are defaults rather than assumptions.
 */
export const ISOMETRIC_DEFAULTS: MapGeometry = {
  // x0 and y0 come from map_info.json; these are the values pzmap2dzi
  // computes for build 42's default cell range.
  originX: 1036288,
  originY: -139296,
  // sqr: two grid widths of 64 pixels.
  squareSize: 128,
  // 1 << skip, where skip is pzmap2dzi's omit_levels.
  scale: 1,
  // 1.5 * sqr. The renderer calls the same number LAYER_HEIGHT.
  floorHeight: 192,
  width: 2314688,
  height: 1021920,
  cellSize: 256,
}

/**
 * Floors build 42 can have: -32 to 31, the range lotheader allows.
 *
 * A render rarely covers all of them -- pzmap2dzi's layer_range usually
 * trims it to the handful a map actually uses -- so this is the outer
 * limit rather than what to show. The floors offered come from the
 * source's own layer list.
 */
export const BUILD_42_FLOORS = { min: -32, max: 31 } as const

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

/** The world's own bounds, which no view may leave. */
export const WORLD_BOUNDS = { minX: 0, maxX: 20000, minY: 0, maxY: 20000 } as const

/**
 * Builds the source for an isometric render the panel actually holds.
 *
 * Everything comes from the render's own map_info.json -- the origin,
 * the square size, the scale a trimmed pyramid declares -- because
 * those change with the options the operator rendered with. Only the
 * floor height is derived, and only because pzmap2dzi derives it the
 * same way: 1.5 squares.
 */
export function isometricSourceFrom(
  levels: number[],
  geometry: MapGeometry | null,
): MapSource | null {
  if (geometry === null || levels.length === 0) {
    return null
  }

  return {
    root: '/api/map/isometric',
    geometry,
    layers: levels.map((level) => ({ level, dzi: `layer${level}.dzi` })),
  }
}

/**
 * What the viewer opens before a render exists.
 *
 * The controls, the floor picker and the coordinate readout all belong
 * on screen while a render is running: tiles appear underneath them as
 * they are drawn, rather than the page staying empty for hours.
 */
export const PENDING_SOURCE: MapSource = {
  root: '/api/map/isometric',
  geometry: ISOMETRIC_DEFAULTS,
  layers: [{ level: 0, dzi: 'layer0.dzi' }],
}
