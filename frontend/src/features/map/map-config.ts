/**
 * Everything the map needs to know about the world it draws.
 *
 * One place, because these numbers appear in the tile source, the
 * coordinate transform, the marker placement and the deep link, and
 * four copies of a constant drift apart.
 */

export type MapProjection = 'isometric' | 'topDown'

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
  projection: MapProjection

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
 * The map the game ships with itself: media/maps/<map>/pyramid.zip.
 *
 * This is Project Zomboid's own in-game map, drawn top-down. Level 0 is
 * 19968x16128, exactly the world in squares, so one pixel is one square
 * and one tile is one cell. It is orthogonal because the game draws it
 * that way -- no viewer can turn it into an isometric view.
 */
export const GAME_MAP_SOURCE: MapSource = {
  projection: 'topDown',
  root: '/api/map/tiles',
  geometry: {
    originX: 0,
    originY: 0,
    squareSize: 1,
    scale: 1,
    floorHeight: 0,
    width: 19968,
    height: 16128,
    cellSize: 256,
  },
  /**
   * The floors the world has, even though this map draws one image for
   * all of them: the game's own map is flat. Offering them keeps the
   * control usable -- the floor travels into markers and the URL -- and
   * an isometric render later swaps real tiles behind the same buttons.
   */
  layers: [7, 6, 5, 4, 3, 2, 1, 0, -1].map((level) => ({ level, dzi: '' })),
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

/** Places worth jumping to, in world coordinates. */
export const QUICK_TARGETS = [
  { id: 'muldraugh', x: 10778, y: 9770 },
  { id: 'westPoint', x: 11800, y: 6900 },
  { id: 'riverside', x: 6500, y: 5300 },
  { id: 'rosewood', x: 8000, y: 11800 },
  { id: 'marchRidge', x: 10100, y: 12800 },
  { id: 'louisville', x: 12800, y: 2000 },
] as const

/** The world's own bounds, which no view may leave. */
export const WORLD_BOUNDS = { minX: 0, maxX: 20000, minY: 0, maxY: 20000 } as const
