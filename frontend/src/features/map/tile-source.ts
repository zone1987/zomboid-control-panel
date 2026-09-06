import type OpenSeadragon from 'openseadragon'

import { floorFor, type MapSource } from './map-config'

/**
 * What OpenSeadragon accepts as a source.
 *
 * Its options type and its open() signature name overlapping but not
 * identical shapes, neither exported. Taking it from the options is the
 * wider of the two, and open() is given the same value.
 */
export type TileSpecifier = NonNullable<OpenSeadragon.Options['tileSources']>

export function tileSourceFor(source: MapSource, floor: number): TileSpecifier {
  floor = floorFor(source, floor)
  // OSD accepts positioned inline DZI sources; its v5 typings omit this form.
  return source.layers
    .filter(({ level }) => level <= floor && (floor < 0 ? level < 0 : level >= 0))
    .map(({ level }) => ({
        tileSource: {
          Image: {
            xmlns: 'http://schemas.microsoft.com/deepzoom/2008',
            Url: `${source.root}/layer${level}_files/`,
            Format: level === 0 ? 'jpg' : 'webp',
            Overlap: 0,
            TileSize: source.tileSize ?? 1024,
            Size: { Width: source.geometry.width, Height: source.geometry.height },
          },
        },
        x: 0,
        y: 0,
        width: 1,
      })) as unknown as TileSpecifier
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
