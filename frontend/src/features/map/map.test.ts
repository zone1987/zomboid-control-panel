import { describe, expect, it } from 'vitest'

import { leafletZoomOf, levelOfLeafletZoom, parseCoordinates } from './map'

describe('parseCoordinates', () => {
  it('reads the comma form the game itself prints', () => {
    expect(parseCoordinates('10778,9770')).toEqual({ x: 10778, y: 9770 })
  })

  it('allows spaces around the separator', () => {
    expect(parseCoordinates('  10778 , 9770 ')).toEqual({ x: 10778, y: 9770 })
  })

  it('accepts a space or an x as the separator', () => {
    expect(parseCoordinates('6500 5300')).toEqual({ x: 6500, y: 5300 })
    expect(parseCoordinates('6500x5300')).toEqual({ x: 6500, y: 5300 })
  })

  it('is not a player name', () => {
    expect(parseCoordinates('bob')).toBeNull()
    expect(parseCoordinates('10778')).toBeNull()
    expect(parseCoordinates('')).toBeNull()
  })

  it('refuses a number longer than any world coordinate', () => {
    expect(parseCoordinates('123456,1')).toBeNull()
  })
})

/**
 * Leaflet's zoom 0 is the whole world; the pyramid's level 0 is the most
 * detailed. Getting this backwards asks for tiles that do not exist.
 */
describe('zoom and pyramid levels', () => {
  it('turns the deepest zoom into the finest level', () => {
    expect(levelOfLeafletZoom(4, 4)).toBe(0)
    expect(levelOfLeafletZoom(0, 4)).toBe(4)
  })

  it('converts back the same way', () => {
    for (const level of [0, 1, 2, 3, 4]) {
      expect(levelOfLeafletZoom(leafletZoomOf(level, 4), 4)).toBe(level)
    }
  })
})
