import { describe, expect, it } from 'vitest'

import { worldToImage } from './coordinates'
import { PROJECT_ZOMBOID_MAP, floorFor } from './map-config'
import { tileSourceFor } from './tile-source'

type InlineLayer = {
  tileSource: { Image: { Url: string; Format: string; Size: { Width: number; Height: number } } }
}

function layers(floor: number): InlineLayer[] {
  return tileSourceFor(PROJECT_ZOMBOID_MAP, floor) as unknown as InlineLayer[]
}

describe('external map images', () => {
  it('opens the ground without a cross-origin descriptor request', () => {
    expect(layers(0)).toHaveLength(1)
    expect(layers(0)[0].tileSource.Image).toMatchObject({
      Url: 'https://tiles.projectzomboidmap.com/maps/b42.20.2-r1/base/layer0_files/',
      Format: 'jpg',
      Size: { Width: 2314368, Height: 1019040 },
    })
  })

  it('keeps the ground under transparent upper floors in drawing order', () => {
    expect(layers(3).map(({ tileSource }) => [tileSource.Image.Url.split('/').at(-2), tileSource.Image.Format])).toEqual([
      ['layer0_files', 'jpg'],
      ['layer1_files', 'webp'],
      ['layer2_files', 'webp'],
      ['layer3_files', 'webp'],
    ])
  })

  it('draws basements from the deepest floor without an opaque ground above them', () => {
    const basement = layers(-1)
    expect(basement).toHaveLength(17)
    expect(basement[0].tileSource.Image.Url).toContain('layer-17_files/')
    expect(basement.at(-1)?.tileSource.Image.Url).toContain('layer-1_files/')
    expect(basement.every(({ tileSource }) => tileSource.Image.Format === 'webp')).toBe(true)
  })

  it('supports all floors of the published render', () => {
    expect(layers(29)).toHaveLength(30)
    expect(floorFor(PROJECT_ZOMBOID_MAP, -17)).toBe(-17)
    expect(floorFor(PROJECT_ZOMBOID_MAP, 29)).toBe(29)
  })

  it('opens the ground for an invalid deep-link floor', () => {
    expect(floorFor(PROJECT_ZOMBOID_MAP, 99)).toBe(0)
    expect(layers(-32)).toEqual(layers(0))
  })

  it('places West Point using the external origin instead of the former local render', () => {
    expect(worldToImage({ x: 11800, y: 6900 }, 0, PROJECT_ZOMBOID_MAP)).toEqual({
      x: 1349888,
      y: 459104,
    })
    expect(worldToImage({ x: 11800, y: 6900 }, 3, PROJECT_ZOMBOID_MAP).y).toBe(458528)
  })
})
