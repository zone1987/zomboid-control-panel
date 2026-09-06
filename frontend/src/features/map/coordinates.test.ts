import { describe, expect, it } from 'vitest'

import {
  imageToViewport,
  imageToWorld,
  isInsideWorld,
  viewportToWorld,
  worldToImage,
  worldToViewport,
} from './coordinates'
import { PROJECT_ZOMBOID_MAP, type MapSource } from './map-config'

const ISOMETRIC = PROJECT_ZOMBOID_MAP

describe('isometric projection', () => {
  /**
   * The grid is rotated 45 degrees: moving one square east and one south
   * moves straight down the image, not diagonally.
   */
  it('turns a diagonal world step into a vertical image step', () => {
    const origin = worldToImage({ x: 1000, y: 1000 }, 0, ISOMETRIC)
    const stepped = worldToImage({ x: 1001, y: 1001 }, 0, ISOMETRIC)

    expect(stepped.x).toBeCloseTo(origin.x, 6)
    expect(stepped.y).toBeGreaterThan(origin.y)
  })

  it('turns an anti-diagonal world step into a horizontal image step', () => {
    const origin = worldToImage({ x: 1000, y: 1000 }, 0, ISOMETRIC)
    const stepped = worldToImage({ x: 1001, y: 999 }, 0, ISOMETRIC)

    expect(stepped.y).toBeCloseTo(origin.y, 6)
    expect(stepped.x).toBeGreaterThan(origin.x)
  })

  it('comes back to where it started', () => {
    const world = { x: 10778, y: 9770 }
    const image = worldToImage(world, 0, ISOMETRIC)
    const back = imageToWorld(image, 0, ISOMETRIC)

    expect(back.x).toBeCloseTo(world.x, 6)
    expect(back.y).toBeCloseTo(world.y, 6)
  })

  /** A marker on the third floor has to sit above the ground floor. */
  it('draws a higher floor higher up the image', () => {
    const ground = worldToImage({ x: 1000, y: 1000 }, 0, ISOMETRIC)
    const third = worldToImage({ x: 1000, y: 1000 }, 3, ISOMETRIC)

    expect(third.y).toBeLessThan(ground.y)
    expect(third.x).toBeCloseTo(ground.x, 6)
  })

  it('comes back from a floor above the ground', () => {
    const world = { x: 8000, y: 11800 }
    const image = worldToImage(world, 4, ISOMETRIC)
    const back = imageToWorld(image, 4, ISOMETRIC)

    expect(back.x).toBeCloseTo(world.x, 6)
    expect(back.y).toBeCloseTo(world.y, 6)
  })

  /** A basement is below the ground, and the sign has to survive. */
  it('handles a basement', () => {
    const world = { x: 5000, y: 5000 }
    const image = worldToImage(world, -1, ISOMETRIC)

    expect(image.y).toBeGreaterThan(worldToImage(world, 0, ISOMETRIC).y)
    expect(imageToWorld(image, -1, ISOMETRIC).x).toBeCloseTo(world.x, 6)
  })
})

/**
 * The formula pzmap2dzi's own viewer uses, in coordinates.js:
 *
 *     px = (x0 + (sx - sy) * sqr / 2) / scale
 *     py = (y0 + (sx + sy) * sqr / 4 - 1.5 * layer * sqr) / scale
 *
 * With sqr = 128 that is 64 and 32 per axis and 192 per floor, which is
 * what the renderer calls GRID_WIDTH, GRID_HEIGHT and LAYER_HEIGHT.
 */
describe('agreement with pzmap2dzi', () => {
  const { originX, originY } = PROJECT_ZOMBOID_MAP.geometry

  const UNSCALED: MapSource = {
    ...ISOMETRIC,
    geometry: { ...PROJECT_ZOMBOID_MAP.geometry, scale: 1 },
  }

  it('matches the reference formula on the ground', () => {
    const world = { x: 10778, y: 9770 }
    const image = worldToImage(world, 0, UNSCALED)

    expect(image.x).toBeCloseTo(originX + (world.x - world.y) * 64, 6)
    expect(image.y).toBeCloseTo(originY + (world.x + world.y) * 32, 6)
  })

  it('matches the reference formula on an upper floor', () => {
    const world = { x: 8000, y: 11800 }
    const image = worldToImage(world, 3, UNSCALED)

    expect(image.x).toBeCloseTo(originX + (world.x - world.y) * 64, 6)
    expect(image.y).toBeCloseTo(originY + (world.x + world.y) * 32 - 3 * 192, 6)
  })

  /** omit_levels shrinks the image; scale puts the numbers back. */
  it('divides by the scale a trimmed pyramid declares', () => {
    const trimmed: MapSource = {
      ...ISOMETRIC,
      geometry: { ...PROJECT_ZOMBOID_MAP.geometry, scale: 4 },
    }
    const world = { x: 5000, y: 5000 }

    const full = worldToImage(world, 0, UNSCALED)
    const small = worldToImage(world, 0, trimmed)

    expect(small.x).toBeCloseTo(full.x / 4, 6)
    expect(small.y).toBeCloseTo(full.y / 4, 6)
    expect(imageToWorld(small, 0, trimmed).x).toBeCloseTo(world.x, 6)
  })
})

describe('viewport coordinates', () => {
  /**
   * OpenSeadragon measures everything in image widths, so x runs 0..1
   * and y ends at height/width rather than at 1.
   */
  it('scales both axes by the image width', () => {
    const { width, height } = ISOMETRIC.geometry
    const point = imageToViewport({ x: width / 2, y: height }, ISOMETRIC.geometry)

    expect(point.x).toBeCloseTo(0.5, 6)
    expect(point.y).toBeCloseTo(height / width, 6)
  })

  it('takes a world point all the way to the viewport and back', () => {
    const world = { x: 11800, y: 6900 }
    const viewport = worldToViewport(world, 0, ISOMETRIC)
    const back = viewportToWorld(viewport, 0, ISOMETRIC)

    expect(back.x).toBeCloseTo(world.x, 6)
    expect(back.y).toBeCloseTo(world.y, 6)
  })

  it('does the same round trip isometrically', () => {
    const world = { x: 10100, y: 12800 }
    const viewport = worldToViewport(world, 2, ISOMETRIC)
    const back = viewportToWorld(viewport, 2, ISOMETRIC)

    expect(back.x).toBeCloseTo(world.x, 4)
    expect(back.y).toBeCloseTo(world.y, 4)
  })
})

describe('bounds', () => {
  it('accepts a point inside the world', () => {
    expect(isInsideWorld({ x: 10778, y: 9770 }, ISOMETRIC)).toBe(true)
  })

  it('rejects a point past the edge', () => {
    expect(isInsideWorld({ x: -1, y: 100 }, ISOMETRIC)).toBe(false)
    expect(isInsideWorld({ x: 100, y: 99999 }, ISOMETRIC)).toBe(false)
  })
})
