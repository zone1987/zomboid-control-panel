import { describe, expect, it } from 'vitest'

import { displayName, matchesSearch, splitSearch, type Item } from './items'

const item = (overrides: Partial<Item> & { type: string }): Item => ({ ...overrides })

describe('splitSearch', () => {
  it('turns a phrase into lower-case words', () => {
    expect(splitSearch('  Fire Axe ')).toEqual(['fire', 'axe'])
  })

  it('returns nothing for an empty search', () => {
    expect(splitSearch('   ')).toEqual([])
  })
})

describe('matchesSearch', () => {
  const axe = item({ type: 'Base.Axe', name: 'Fire Axe', category: 'Weapon' })

  it('keeps everything while the search is empty', () => {
    expect(matchesSearch(axe, [])).toBe(true)
  })

  it('matches on the displayed name', () => {
    expect(matchesSearch(axe, ['fire'])).toBe(true)
  })

  it('matches on the item type, which is what a script names', () => {
    expect(matchesSearch(axe, ['base.axe'])).toBe(true)
  })

  it('matches on the category', () => {
    expect(matchesSearch(axe, ['weapon'])).toBe(true)
  })

  /** Typing what comes to mind, in whatever order it comes. */
  it('accepts the words in any order', () => {
    expect(matchesSearch(axe, ['axe', 'fire'])).toBe(true)
  })

  it('requires every word to match, not just one', () => {
    expect(matchesSearch(axe, ['fire', 'hammer'])).toBe(false)
  })

  it('rejects an item that matches nothing', () => {
    expect(matchesSearch(axe, ['shotgun'])).toBe(false)
  })

  it('searches an item that has no name of its own', () => {
    expect(matchesSearch(item({ type: 'Base.Nails' }), ['nails'])).toBe(true)
  })
})

describe('displayName', () => {
  it('prefers the name the server translated', () => {
    expect(displayName(item({ type: 'Base.Axe', name: 'Feueraxt' }))).toBe('Feueraxt')
  })

  it('falls back to the type with its module removed', () => {
    expect(displayName(item({ type: 'Base.Nails' }))).toBe('Nails')
  })

  it('separates the words of a run-together type', () => {
    expect(displayName(item({ type: 'Base.WildGarlicCataplasm' }))).toBe('Wild Garlic Cataplasm')
  })

  it('handles a type with no module at all', () => {
    expect(displayName(item({ type: 'Axe' }))).toBe('Axe')
  })

  it('treats an empty name as no name', () => {
    expect(displayName(item({ type: 'Base.Nails', name: '' }))).toBe('Nails')
  })
})
