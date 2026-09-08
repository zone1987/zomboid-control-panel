import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

/**
 * The save button belongs to the tabs that hold fields. On the upload
 * tabs a file is transferred the moment it is dropped, so a save button
 * there reads as though the transfer still needed confirming.
 *
 * Read from the source rather than rendered: this is about the page's
 * own wiring, and a new tab must not silently fall on the wrong side.
 */
describe('which settings tabs offer saving', () => {
  const source = readFileSync(
    new URL('./settings-page.tsx', import.meta.url),
    'utf8',
  )

  const tabs = [...source.matchAll(/TabsTrigger value="([a-z]+)"/g)].map(([, id]) => id)
  const savable = /const SAVABLE_TABS = \[([^\]]*)\]/
    .exec(source)?.[1]
    .split(',')
    .map((entry) => entry.trim().replace(/'/g, ''))
    .filter((entry) => entry !== '')

  it('finds the tabs and the list', () => {
    expect(tabs.length).toBeGreaterThan(0)
    expect(savable).toBeDefined()
  })

  it('names only tabs that exist', () => {
    for (const id of savable ?? []) {
      expect(tabs, `"${id}" is listed as savable but is not a tab`).toContain(id)
    }
  })

  it('offers saving on the credential tabs', () => {
    expect(savable).toContain('steam')
    expect(savable).toContain('google')
    expect(savable).toContain('mail')
  })

  it('offers no saving on the tabs that upload files', () => {
    expect(savable).not.toContain('icons')
    expect(savable).not.toContain('vehicles')
  })

  /** Clearing is not saving: a save button there would offer nothing. */
  it('offers no saving on the cache tab', () => {
    expect(savable).not.toContain('cache')
  })
})

/**
 * The phone gets a select built from SECTIONS while wider screens get
 * the TabsList, so a tab added to one and not the other is a control
 * that exists at 1512px and not at 390px.
 */
describe('the section list and the tab list', () => {
  const source = readFileSync(
    new URL('./settings-page.tsx', import.meta.url),
    'utf8',
  )

  const tabs = [...source.matchAll(/TabsTrigger value="([a-z]+)"/g)].map(([, id]) => id)
  const sections = [...source.matchAll(/\{ id: '([a-z]+)', label:/g)].map(([, id]) => id)
  const panels = [...source.matchAll(/TabsContent value="([a-z]+)"/g)].map(([, id]) => id)

  it('finds both lists', () => {
    expect(tabs.length).toBeGreaterThan(0)
    expect(sections.length).toBeGreaterThan(0)
  })

  it('names the same tabs in the same order', () => {
    expect(sections).toStrictEqual(tabs)
  })

  it('gives every tab a panel to open', () => {
    for (const id of tabs) {
      expect(panels, `"${id}" is a tab with no content`).toContain(id)
    }
  })
})
