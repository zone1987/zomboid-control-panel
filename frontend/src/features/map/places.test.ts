import { describe, expect, it } from 'vitest'

import { worldToImage } from './coordinates'
import { PROJECT_ZOMBOID_MAP, QUICK_TARGETS } from './map-config'
import { ALL_LAYERS_ON, MAP_LAYERS } from './layer-toggles'

describe('place name layer', () => {
  it('offers a switch of its own next to the other layers', () => {
    expect(MAP_LAYERS.map(({ id }) => id)).toContain('places')
  })

  it('starts switched on, like every other layer', () => {
    expect(ALL_LAYERS_ON.places).toBe(true)
  })

  it('names every place it draws', () => {
    expect(QUICK_TARGETS).toHaveLength(12)

    for (const place of QUICK_TARGETS) {
      expect(place.id).not.toBe('')
    }
  })

  it('draws every name the same size, with no per-place scale', () => {
    // The game's own setScale runs from 4 to 10; carried straight into
    // CSS that made Louisville tower over the map.
    for (const place of QUICK_TARGETS) {
      expect(place).not.toHaveProperty('scale')
      expect(place).not.toHaveProperty('rank')
    }
  })

  it('places every label inside the rendered image', () => {
    const { width, height } = PROJECT_ZOMBOID_MAP.geometry

    for (const place of QUICK_TARGETS) {
      const point = worldToImage({ x: place.x, y: place.y }, 0, PROJECT_ZOMBOID_MAP)

      expect(point.x).toBeGreaterThanOrEqual(0)
      expect(point.y).toBeGreaterThanOrEqual(0)
      expect(point.x).toBeLessThanOrEqual(width)
      expect(point.y).toBeLessThanOrEqual(height)
    }
  })

  it('uses the game\'s own label positions, not the start-area camera', () => {
    // map.info's zoomX/zoomY are a start area's camera point. Muldraugh's
    // (11181, 9725) drew its name in the woods east of the town, and every
    // jump target missed by the same distance. These are the coordinates
    // the game itself labels the towns at, from worldmap-annotations.lua.
    const by = (id: string) => QUICK_TARGETS.find((place) => place.id === id)

    expect(by('muldraugh')).toMatchObject({ x: 10754, y: 9926 })
    expect(by('louisville')).toMatchObject({ x: 13077, y: 2238 })
    expect(by('ekron')).toMatchObject({ x: 634, y: 9746 })

    for (const [id, camera] of [
      ['muldraugh', { x: 11181, y: 9725 }],
      ['riverside', { x: 6300, y: 5668 }],
      ['valleyStation', { x: 13056, y: 6031 }],
    ] as const) {
      expect(by(id)).not.toMatchObject(camera)
    }
  })

  it('pins a label to the ground, so changing floor does not move the town', () => {
    const muldraugh = QUICK_TARGETS.find((place) => place.id === 'muldraugh')

    if (muldraugh === undefined) {
      throw new Error('Muldraugh is missing from the place list.')
    }

    const point = { x: muldraugh.x, y: muldraugh.y }

    expect(worldToImage(point, 0, PROJECT_ZOMBOID_MAP)).toEqual(
      worldToImage(point, 0, PROJECT_ZOMBOID_MAP),
    )
    // Floor 6 draws 6 * floorHeight higher up the image; a label that
    // followed the selected floor would drift off its town by that much.
    expect(worldToImage(point, 6, PROJECT_ZOMBOID_MAP).y).toBeLessThan(
      worldToImage(point, 0, PROJECT_ZOMBOID_MAP).y,
    )
  })
})
