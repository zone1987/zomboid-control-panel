import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

import { parseZomboidMesh } from './zomboid-mesh'

/**
 * The fixture is the game's own media/models/Vehicles_Wheel.txt. A
 * synthesised one would only prove the reader agrees with itself.
 */
const WHEEL = readFileSync(
  new URL('./__fixtures__/wheel.mesh.txt', import.meta.url),
  'utf8',
)

describe('reading the game\'s own mesh format', () => {
  it('reads the wheel the vehicles stand on', () => {
    const geometry = parseZomboidMesh(WHEEL)

    expect(geometry).not.toBeNull()
    expect(geometry?.getAttribute('position').count).toBe(52)
  })

  it('takes the vertex count from the file rather than counting lines', () => {
    const geometry = parseZomboidMesh(WHEEL)
    const declared = Number.parseInt(
      WHEEL.split('# Vertex Count:')[1].trim().split('\n')[0],
      10,
    )

    expect(geometry?.getAttribute('position').count).toBe(declared)
  })

  it('reads normals and texture coordinates, not only positions', () => {
    const geometry = parseZomboidMesh(WHEEL)

    expect(geometry?.getAttribute('normal').count).toBe(52)
    expect(geometry?.getAttribute('uv').count).toBe(52)
  })

  /**
   * The format's v axis grows downward and three.js's upward, so it is
   * flipped on the way in -- otherwise every texture is upside down.
   */
  it('flips the texture coordinates to the way three.js reads them', () => {
    const uv = parseZomboidMesh(WHEEL)?.getAttribute('uv')

    if (uv === undefined) {
      throw new Error('No texture coordinates.')
    }

    // The first vertex's raw v in the fixture is 0.18401920.
    const raw = Number.parseFloat(
      WHEEL.split('# Vertex Buffer:')[1].trim().split('\n')[2].split(',')[1],
    )

    expect(uv.getY(0)).toBeCloseTo(1 - raw, 5)
    // And every value still lies inside the texture.
    for (let i = 0; i < uv.count; i += 1) {
      expect(uv.getX(i)).toBeGreaterThanOrEqual(0)
      expect(uv.getY(i)).toBeLessThanOrEqual(1)
    }
  })

  it('reads the triangles', () => {
    const index = parseZomboidMesh(WHEEL)?.getIndex()

    expect(index).not.toBeNull()
    expect((index?.count ?? 0) % 3).toBe(0)
    expect(index?.count).toBeGreaterThan(0)
  })

  /** A wheel is round and small; wrong strides give absurd bounds. */
  it('comes out the shape and size of a wheel', () => {
    const geometry = parseZomboidMesh(WHEEL)

    if (geometry === null) {
      throw new Error('The wheel did not parse.')
    }

    geometry.computeBoundingBox()
    const box = geometry.boundingBox

    if (box === null) {
      throw new Error('No bounds.')
    }

    const height = box.max.y - box.min.y
    const depth = box.max.z - box.min.z
    const width = box.max.x - box.min.x

    // Round: as tall as it is deep.
    expect(height).toBeCloseTo(depth, 2)
    // And narrower than it is tall, being a tyre rather than a ball.
    expect(width).toBeLessThan(height)
    // Model units, roughly a third of a metre across.
    expect(height).toBeGreaterThan(0.1)
    expect(height).toBeLessThan(1)
  })

  /**
   * Refused rather than half-read: a truncated file would otherwise
   * draw a spike across the vehicle.
   */
  it('refuses a file it cannot read whole', () => {
    expect(parseZomboidMesh('')).toBeNull()
    expect(parseZomboidMesh('# Project Zomboid Mesh')).toBeNull()
    expect(parseZomboidMesh(WHEEL.slice(0, 400))).toBeNull()
  })

  it('refuses a triangle pointing past the last vertex', () => {
    const broken = WHEEL.replace(/^51, 40, 39$/m, '51, 40, 999')

    expect(broken).not.toBe(WHEEL)
    expect(parseZomboidMesh(broken)).toBeNull()
  })
})
