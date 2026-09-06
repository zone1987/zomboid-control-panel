import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

import { parseZomboidMesh } from './zomboid-mesh'

/**
 * The wheel is the one mesh the vehicle preview parses itself.
 *
 * Every body is an FBX and goes through three.js's loader; the wheel
 * ships only in the game's own text format, so this parser is the whole
 * path — and it returns null silently, which leaves a car on its axles
 * with no error anywhere.
 *
 * The file was missing entirely until now: the game names the model
 * `Vehicles_Wheel` and the extraction had saved it under its *internal*
 * name, `Vehicle_Wheel` — singular. `ModelStore::has()` then reported
 * false, the catalogue sent `wheelMesh: null`, and nothing complained.
 */
describe('the wheel mesh the game ships', () => {
  const path = '../backend/var/vehicle-models/Vehicles_Wheel.txt'

  it('is present under the name the catalogue asks for', () => {
    expect(() => readFileSync(path)).not.toThrow()
  })

  it('parses into geometry with normals and texture coordinates', () => {
    const geometry = parseZomboidMesh(readFileSync(path, 'utf8'))

    expect(geometry).not.toBeNull()
    expect(geometry?.getAttribute('position')?.count).toBeGreaterThan(0)
    expect(geometry?.getAttribute('normal')).toBeDefined()
    expect(geometry?.getAttribute('uv')).toBeDefined()
  })

  /**
   * Its size decides whether the scaling is right: the mesh is in
   * metres, 0.316 across, which matches the script's own radius of 0.15
   * — so `setScalar(100)` beside offsets multiplied by 100 is
   * consistent, and a wheel drawn at the wrong size would be a
   * different bug from one not drawn at all.
   */
  it('is a wheel-sized thing in metres', () => {
    const geometry = parseZomboidMesh(readFileSync(path, 'utf8'))

    geometry?.computeBoundingBox()

    const box = geometry?.boundingBox

    expect(box).toBeDefined()

    const height = (box?.max.y ?? 0) - (box?.min.y ?? 0)

    // A script declares radius 0.15, so a diameter near 0.3.
    expect(height).toBeGreaterThan(0.25)
    expect(height).toBeLessThan(0.4)
  })
})
