import { describe, expect, it } from 'vitest'

import { ALL_LAYERS_ON, MAP_LAYERS, type MapLayerId } from './layer-toggles'

/**
 * A layer that reads server data needs the permission covering it. The
 * switch stays visible but disabled, so a restriction does not look
 * like a fault.
 */
describe('what each map layer requires', () => {
  const needsOf = (id: MapLayerId) => MAP_LAYERS.find((layer) => layer.id === id)?.needs

  it('guards the vehicles with their own permission, not the players one', () => {
    expect(needsOf('vehicles')).toBe('vehicles.view')
  })

  it('guards players and safehouses with the player permission', () => {
    expect(needsOf('players')).toBe('players.view')
    expect(needsOf('safehouses')).toBe('players.view')
  })

  it('asks for nothing to draw the place names, which are the panel\'s own', () => {
    expect(needsOf('places')).toBeUndefined()
  })

  it('has a switch for every layer that can be shown', () => {
    expect(MAP_LAYERS.map(({ id }) => id).sort()).toEqual(
      Object.keys(ALL_LAYERS_ON).sort(),
    )
  })

  it('starts every layer switched on', () => {
    for (const { id } of MAP_LAYERS) {
      expect(ALL_LAYERS_ON[id]).toBe(true)
    }
  })
})
