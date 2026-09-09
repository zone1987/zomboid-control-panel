import { describe, expect, it } from 'vitest'

import { categoriesOf, displayName, formatSize, matchesSearch, readWorkshopId, type Mod } from './mods'

const mod = (overrides: Partial<Mod> = {}): Mod => ({
  workshopId: '123',
  resolved: true,
  title: 'Fitted Sheets',
  description: '',
  previewUrl: null,
  tags: ['Build 42', 'Textures'],
  fileSize: 1048576,
  createdAt: null,
  updatedAt: null,
  subscriptions: 10,
  favourites: 1,
  views: 100,
  dependencies: [],
  isCollection: false,
  isMap: false,
  declaredBuild: '42',
  declaredBuilds: ['42'],
  buildVerdict: 'match',
  url: 'https://steamcommunity.com/sharedfiles/filedetails/?id=123',
  ...overrides,
})

describe('reading a workshop id from what somebody pasted', () => {
  it('takes a bare id', () => {
    expect(readWorkshopId('3795847162')).toBe('3795847162')
  })

  it('takes the url a browser copies from the workshop', () => {
    expect(readWorkshopId('https://steamcommunity.com/sharedfiles/filedetails/?id=3795847162'))
      .toBe('3795847162')
  })

  it('takes a url carrying more than one parameter', () => {
    expect(readWorkshopId('https://steamcommunity.com/workshop/filedetails/?l=german&id=123'))
      .toBe('123')
  })

  it('ignores surrounding whitespace, which a paste often brings', () => {
    expect(readWorkshopId('  3795847162 ')).toBe('3795847162')
  })

  /** Null rather than a guess: a wrong id installs the wrong mod. */
  it('returns null for something that holds no id', () => {
    expect(readWorkshopId('fitted sheets')).toBeNull()
    expect(readWorkshopId('')).toBeNull()
  })
})

describe('the categories offered as filters', () => {
  it('counts each tag across the mods', () => {
    const found = categoriesOf([
      mod({ tags: ['Textures', 'Items'] }),
      mod({ tags: ['Textures'] }),
    ])

    expect(found).toStrictEqual([
      { tag: 'Textures', count: 2 },
      { tag: 'Items', count: 1 },
    ])
  })

  /**
   * The server already filters by build, so offering it here would be a
   * control that changes nothing whichever way it is set.
   */
  it('leaves the build tag out, because the server applies it already', () => {
    const found = categoriesOf([mod({ tags: ['Build 42', 'Build 41', 'Textures'] })])

    expect(found).toStrictEqual([{ tag: 'Textures', count: 1 }])
  })

  it('puts the most used first and breaks a tie by name', () => {
    const found = categoriesOf([mod({ tags: ['Weapons', 'Audio'] })])

    expect(found.map((entry) => entry.tag)).toStrictEqual(['Audio', 'Weapons'])
  })
})

describe('searching the mods on screen', () => {
  it('matches part of the title', () => {
    expect(matchesSearch(mod(), 'sheet')).toBe(true)
  })

  it('needs every word, so a second word narrows rather than widens', () => {
    expect(matchesSearch(mod(), 'fitted sheets')).toBe(true)
    expect(matchesSearch(mod(), 'fitted curtains')).toBe(false)
  })

  it('matches a tag, which is how somebody looks for a kind of mod', () => {
    expect(matchesSearch(mod(), 'textures')).toBe(true)
  })

  /** The id is what an operator has to hand when a mod broke a start. */
  it('matches the workshop id', () => {
    expect(matchesSearch(mod(), '123')).toBe(true)
  })

  it('shows everything when nothing was typed', () => {
    expect(matchesSearch(mod(), '')).toBe(true)
  })

  it('still finds a mod the workshop could not describe, by its id', () => {
    expect(matchesSearch(mod({ resolved: false, title: null }), '123')).toBe(true)
  })
})

describe('naming a mod', () => {
  it('uses the title when there is one', () => {
    expect(displayName(mod())).toBe('Fitted Sheets')
  })

  /** An unresolved mod is still installed, so it needs a label. */
  it('falls back to the id when the workshop said nothing', () => {
    expect(displayName(mod({ title: null }))).toBe('123')
  })
})

describe('showing a file size', () => {
  it('reads megabytes above a megabyte', () => {
    expect(formatSize(11_477_000)).toBe('10.9 MB')
  })

  it('reads kilobytes below one', () => {
    expect(formatSize(51_200)).toBe('50 KB')
  })

  /** Null, not "0 MB": the workshop simply did not say. */
  it('says nothing when there is no size', () => {
    expect(formatSize(null)).toBeNull()
    expect(formatSize(0)).toBeNull()
  })
})
