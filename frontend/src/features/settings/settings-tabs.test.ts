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
})
