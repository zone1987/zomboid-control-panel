import OpenSeadragon from 'openseadragon'

import type { MapSource } from './map-config'

/**
 * What OpenSeadragon accepts as a source.
 *
 * Its options type and its open() signature name overlapping but not
 * identical shapes, neither exported. Taking it from the options is the
 * wider of the two, and open() is given the same value.
 */
export type TileSpecifier = NonNullable<OpenSeadragon.Options['tileSources']>

/**
 * The tile source for one floor.
 *
 * Built from what pzmap2dzi wrote beside the tiles.
 * An isometric render is a Deep Zoom pyramid with its own .dzi per
 * floor, which OpenSeadragon reads directly. The game's own map is a
 * flat pyramid of 256-pixel tiles named tile<col>x<row>, one floor
 * only, so it needs a source that knows that naming.
 */
export function tileSourceFor(source: MapSource, floor: number): TileSpecifier {
  {
    const layer = source.layers.find((entry) => entry.level === floor) ?? source.layers[0]

    return `${source.root}/${layer.dzi}`
  }

  return gameMapSource(source)
}

/**
 * The pyramid the game ships, described for OpenSeadragon.
 *
 * Its levels count the other way round from OpenSeadragon's: level 0 is
 * the most detailed there, and the deepest zoom here. getTileUrl turns
 * one into the other, which is the whole reason this is a custom source
 * rather than a URL template.
 */
function gameMapSource(source: MapSource): TileSpecifier {
  const { width, height } = source.geometry
  const tileSize = source.geometry.cellSize
  const deepest = Math.ceil(Math.log2(Math.max(width, height) / tileSize))

  // Subclassed rather than configured: getTileUrl is a method on the
  // prototype, and the option object has no place for it.
  class GameMapTileSource extends OpenSeadragon.TileSource {
    override getTileUrl(level: number, x: number, y: number): string {
      return `${source.root}/${deepest - level}/${x}/${y}.png`
    }
  }

  return new GameMapTileSource({
    width,
    height,
    tileSize,
    tileOverlap: 0,
    minLevel: deepest - 4,
    maxLevel: deepest,
  }) as unknown as TileSpecifier
}

/**
 * How far out OpenSeadragon may zoom before the whole world fits.
 *
 * Left to itself it stops at the image width, which on a map this wide
 * means the top and bottom are cut off.
 */
export function fitZoom(source: MapSource, viewportAspect: number): number {
  const { width, height } = source.geometry
  const imageAspect = width / height

  return imageAspect > viewportAspect ? 1 : imageAspect / viewportAspect
}
