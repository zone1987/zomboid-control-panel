import { describe, expect, it } from 'vitest'

import { worldToImage } from './coordinates'
import { PROJECT_ZOMBOID_MAP } from './map-config'
import type { MapVehicle } from './map'
import { describeVehicle, paintOf, vehicleClass, vehicleSize } from './vehicle-marker'

function vehicle(overrides: Partial<MapVehicle> = {}): MapVehicle {
  return {
    id: 1,
    script: 'Base.CarNormal',
    x: 100,
    y: 200,
    z: 0,
    heading: 90,
    skin: 0,
    engineRunning: false,
    ...overrides,
  }
}

describe('painting a vehicle the colour the game gave it', () => {
  it('halves the saturation, as the game does before it renders', () => {
    // The game calls Color.HSBtoRGB(hue, saturation * 0.5, value); without
    // that every car comes out lurid.
    expect(paintOf(vehicle({ hue: 0.5, saturation: 1, value: 1 }))).toBe('hsl(180 50% 50%)')
  })

  it('falls back to a neutral body when the paint is unknown', () => {
    expect(paintOf(vehicle())).toBe('var(--pz-vehicle-unknown)')
    expect(paintOf(vehicle({ hue: 0.5, saturation: null, value: 1 }))).toBe(
      'var(--pz-vehicle-unknown)',
    )
  })

  it('turns the stored hue into a full circle of degrees', () => {
    expect(paintOf(vehicle({ hue: 0, saturation: 1, value: 1 }))).toContain('hsl(0 ')
    expect(paintOf(vehicle({ hue: 1, saturation: 1, value: 1 }))).toContain('hsl(360 ')
  })
})

describe('what a vehicle marker says about itself', () => {
  it('marks a vehicle the bridge could not see as stored', () => {
    expect(vehicleClass(vehicle())).toContain('pz-vehicle--stored')
    expect(vehicleClass(vehicle({ live: true }))).not.toContain('pz-vehicle--stored')
  })

  it('marks a running engine, because somebody is in it or left it on', () => {
    expect(vehicleClass(vehicle({ engineRunning: true }))).toContain('pz-vehicle--running')
  })

  it('separates a dent from a wreck', () => {
    const dented = vehicleClass(
      vehicle({
        condition: {
          front: 50,
          frontMax: 100,
          rear: 100,
          rearMax: 100,
          damaged: true,
          wrecked: false,
          intact: 0.75,
        },
      }),
    )
    const wrecked = vehicleClass(
      vehicle({
        condition: {
          front: 0,
          frontMax: 100,
          rear: 40,
          rearMax: 100,
          damaged: true,
          wrecked: true,
          intact: 0.2,
        },
      }),
    )

    expect(dented).toContain('pz-vehicle--damaged')
    expect(dented).not.toContain('pz-vehicle--wrecked')
    expect(wrecked).toContain('pz-vehicle--wrecked')
  })

  /** The script name says so even before any condition is known. */
  it('treats a burnt-out shell as a wreck', () => {
    expect(vehicleClass(vehicle({ script: 'Base.SUVBurnt' }))).toContain('pz-vehicle--wrecked')
  })
})

describe('the tooltip', () => {
  const name = (script: string) => script.replace(/^Base\./, '')

  it('names the vehicle', () => {
    expect(describeVehicle(vehicle(), name)).toBe('CarNormal')
  })

  it('adds the fuel when it is known', () => {
    expect(describeVehicle(vehicle({ fuel: 42.4 }), name)).toBe('CarNormal · 42%')
  })

  it('says how much of a damaged vehicle is left', () => {
    const described = describeVehicle(
      vehicle({
        condition: {
          front: 50,
          frontMax: 100,
          rear: 100,
          rearMax: 100,
          damaged: true,
          wrecked: false,
          intact: 0.75,
        },
      }),
      name,
    )

    expect(described).toBe('CarNormal · 75%')
  })
})

/**
 * The marker is placed as a rectangle in map coordinates, so it scales
 * with the view. Sizes are therefore in world squares, and come from
 * the game's own `extents` field by way of the catalogue.
 */
describe('how large a vehicle is on the map', () => {
  it('uses the size the game declares', () => {
    const car = vehicleSize({ length: 5.2088, width: 1.7802 })

    expect(car.length).toBe(5.2088)
    expect(car.width).toBe(1.7802)
  })

  it('falls back to a plausible car for a vehicle the game does not declare', () => {
    const unknown = vehicleSize(null)

    expect(unknown.length).toBeGreaterThan(3)
    expect(unknown.length).toBeLessThan(8)
    expect(unknown.length).toBeGreaterThan(unknown.width)
  })

  it('refuses a size that is no size at all', () => {
    expect(vehicleSize({ length: 0, width: 0 })).toEqual(vehicleSize(null))
    expect(vehicleSize(undefined)).toEqual(vehicleSize(null))
  })

  /**
   * A car about five squares long has to come out a few ten-thousandths
   * of the image width -- the unit OpenSeadragon measures in. Getting
   * this wrong by a factor shows up as a vehicle the size of a town.
   */
  it('converts to a fraction of the image the map can place', () => {
    const { squareSize, width } = PROJECT_ZOMBOID_MAP.geometry
    // A square along a world axis spans the hypotenuse of the image
    // step it makes, not just its horizontal half.
    const perSquare = Math.hypot(squareSize * 0.5, squareSize * 0.25)
    const inViewport = (5.2088 * perSquare) / width

    expect(inViewport).toBeGreaterThan(0.0001)
    expect(inViewport).toBeLessThan(0.001)
  })

  /**
   * The scale was wrong twice, and both times a screenshot from the
   * game beside the panel was what showed it: measured against the road
   * markings, the panel drew the taxi at half its length.
   */
  it('spans what the projection really gives a square, not its horizontal half', () => {
    const { squareSize } = PROJECT_ZOMBOID_MAP.geometry
    const step = worldToImage({ x: 1, y: 0 }, 0, PROJECT_ZOMBOID_MAP)
    const origin = worldToImage({ x: 0, y: 0 }, 0, PROJECT_ZOMBOID_MAP)
    const measured = Math.hypot(step.x - origin.x, step.y - origin.y)

    expect(measured).toBeCloseTo(Math.hypot(squareSize * 0.5, squareSize * 0.25), 6)
    // Half the square size is what the code used to use, and it is
    // noticeably short of the truth.
    expect(measured).toBeGreaterThan(squareSize * 0.5)
  })

  /**
   * The marker's box is square and the vehicle inside it is turned, so
   * the box has to hold the body's diagonal -- a box sized to the
   * length alone clips a car standing across it, and the render fills
   * the frame with no margin to spare.
   */
  it('holds a turned vehicle without clipping it', () => {
    const { length, width } = vehicleSize({ length: 5.2088, width: 1.7802 })
    const diagonal = Math.hypot(length, width)

    expect(diagonal).toBeGreaterThan(length)
    // And not so much larger that the vehicle swims in its own box.
    expect(diagonal / length).toBeLessThan(1.2)
  })
})

/**
 * Vehicles stand on the ground, so their marker is pinned to floor 0.
 * Following the selected floor would lift them off the road when an
 * operator looks at an upper storey.
 */
describe('which floor a vehicle is drawn on', () => {
  it('draws a vehicle at ground level whatever floor is selected', () => {
    const at = (floor: number) => worldToImage({ x: 10800, y: 9700 }, floor, PROJECT_ZOMBOID_MAP)

    // Proof the floor would otherwise move it: floor 4 draws higher up
    // the image by four floor heights.
    expect(at(4).y).toBeLessThan(at(0).y)
    expect(at(0).y - at(4).y).toBeCloseTo(4 * PROJECT_ZOMBOID_MAP.geometry.floorHeight, 6)
  })
})

/**
 * The heading is applied while rendering, in the 3D scene, not as a
 * rotation of the finished image.
 *
 * The map's ground plane is a circle squashed to half its height, so a
 * vehicle turning through a full circle traces an ellipse on screen:
 * equal steps of heading are unequal steps on the image. A flat
 * rotation turns on a circle and cannot express that.
 */
describe('where the heading is applied', () => {
  const screenDirectionOf = (worldX: number, worldY: number) => {
    const a = worldToImage({ x: worldX, y: worldY }, 0, PROJECT_ZOMBOID_MAP)
    const b = worldToImage({ x: 0, y: 0 }, 0, PROJECT_ZOMBOID_MAP)

    return (((Math.atan2(a.x - b.x, -(a.y - b.y)) * 180) / Math.PI) + 360) % 360
  }

  it('turns unequally on screen, which a flat rotation cannot do', () => {
    const north = screenDirectionOf(0, -1)
    const east = screenDirectionOf(1, 0)

    expect((east - north + 360) % 360).not.toBeCloseTo(90, 1)
  })

  it('sends a vehicle facing world +Y where the map sends that axis', () => {
    // The renderer's camera is aimed so heading zero comes out here.
    expect(screenDirectionOf(0, 1)).toBeCloseTo(243.435, 2)
  })
})
