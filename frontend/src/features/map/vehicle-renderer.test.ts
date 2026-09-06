import { describe, expect, it } from 'vitest'

import { PROJECT_ZOMBOID_MAP } from './map-config'
import type { MapVehicle } from './map'
import {
  bucketedHeading,
  ELEVATION_DEGREES,
  HEIGHT_CORRECTION,
  YAW_DEGREES,
} from './vehicle-renderer'

/**
 * A vehicle rendered straight down would lie flat on a world drawn at
 * an angle, so the camera has to match the map's own projection rather
 * than be chosen by eye.
 */
describe('the camera the vehicles are drawn with', () => {
  const { squareSize } = PROJECT_ZOMBOID_MAP.geometry

  it('tilts by exactly what the map\'s vertical squash implies', () => {
    // worldToImage puts one square at (squareSize/2, squareSize/4), so
    // the vertical axis is halved: elevation = asin(0.5) = 30°.
    const squash = squareSize * 0.25 / (squareSize * 0.5)
    const implied = (Math.asin(squash) * 180) / Math.PI

    expect(squash).toBe(0.5)
    expect(ELEVATION_DEGREES).toBeCloseTo(implied, 6)
  })

  /**
   * Two yaws put the vehicle's long axis on the right screen line, one
   * viewing its front and one its back. Which is which cannot be
   * derived; this was settled in a browser against the same taxi seen
   * in the game.
   */
  it('looks at the front of the vehicle, not its back', () => {
    expect(YAW_DEGREES).toBe(225)
    // Still on the diagonal the map's turned grid needs.
    expect(YAW_DEGREES % 90).toBe(45)
  })

  it('does not look straight down, which would render a flat rectangle', () => {
    expect(ELEVATION_DEGREES).toBeGreaterThan(0)
    expect(ELEVATION_DEGREES).toBeLessThan(90)
  })

  /**
   * The map is the 2:1 projection 2D games use: the ground is squashed
   * to half but height is drawn at full scale. Its own geometry proves
   * it -- a storey is 192 pixels and a Zomboid storey is three metres,
   * which is 64 pixels a metre, the same as along the ground.
   */
  it('draws height at the same scale the map does', () => {
    const { squareSize, floorHeight } = PROJECT_ZOMBOID_MAP.geometry
    const metresPerStorey = 3
    const pixelsPerMetreUp = floorHeight / metresPerStorey
    const pixelsPerMetreAlong = squareSize * 0.5

    expect(pixelsPerMetreUp).toBe(pixelsPerMetreAlong)

    // A camera tilted to squash the ground squashes height by cos of
    // the tilt, so the model is stretched by the inverse.
    const squashed = Math.cos((ELEVATION_DEGREES * Math.PI) / 180)

    expect(HEIGHT_CORRECTION).toBeCloseTo(1 / squashed, 6)
    expect(HEIGHT_CORRECTION * squashed).toBeCloseTo(1, 6)
  })
})

/**
 * Each heading bucket is a render of its own, kept and reused. The step
 * trades memory for smoothness, and the turn must use the bucketed
 * value or a cached picture is handed out slightly askew.
 */
describe('bucketing the heading for the cache', () => {
  const at = (heading: number | null): MapVehicle => ({
    id: 1,
    script: 'Base.CarNormal',
    x: 0,
    y: 0,
    z: 0,
    heading,
    skin: 0,
    engineRunning: false,
  })

  it('rounds to the nearest step', () => {
    expect(bucketedHeading(at(0))).toBe(0)
    expect(bucketedHeading(at(7))).toBe(0)
    expect(bucketedHeading(at(8))).toBe(15)
    expect(bucketedHeading(at(97))).toBe(90)
    expect(bucketedHeading(at(98))).toBe(105)
  })

  it('keeps every bucket inside one turn', () => {
    for (const heading of [0, 353, 358, 360, 720, -15]) {
      const bucket = bucketedHeading(at(heading))

      expect(bucket).toBeGreaterThanOrEqual(0)
      expect(bucket).toBeLessThan(360)
    }
  })

  it('treats an unknown heading as facing zero', () => {
    expect(bucketedHeading(at(null))).toBe(0)
  })

  /** Twenty-four buckets a type, whatever the world holds. */
  it('caps how many renders one vehicle type can need', () => {
    const buckets = new Set<number>()

    for (let heading = 0; heading < 360; heading += 1) {
      buckets.add(bucketedHeading(at(heading)))
    }

    expect(buckets.size).toBe(24)
  })
})
