import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

import { pagesOf, SERVER_PAGES, SERVER_SECTIONS } from './server-pages'

const SIDEBAR = readFileSync('src/components/layout/app-sidebar.tsx', 'utf8')
const ROUTER = readFileSync('src/routes/router.tsx', 'utf8')

/**
 * The router is mounted under a basename, so a link that repeats it sends
 * the browser to /app/app/... and the route does not match. That mistake
 * is invisible in the source and only shows on the click.
 */
describe('the sidebar links against the router basename', () => {
  const basename = ROUTER.match(/basename:\s*'([^']+)'/)?.[1]

  it('the router still has a basename to guard against', () => {
    expect(basename).toBe('/app')
  })

  it('no link repeats the basename', () => {
    const links = [...SIDEBAR.matchAll(/\bto="(\/[^"]*)"/g)].map((match) => match[1])

    expect(links.length).toBeGreaterThan(0)

    for (const link of links) {
      expect(link, `${link} repeats the basename`).not.toMatch(
        new RegExp(`^${basename}(/|$)`),
      )
    }
  })
})

describe('the sidebar sections', () => {
  it('gives every page a section that the sidebar renders', () => {
    for (const page of SERVER_PAGES) {
      expect(
        SERVER_SECTIONS.map((section) => section.id),
        `${page.path} sits in a section the sidebar does not draw`,
      ).toContain(page.section)
    }
  })

  /** A heading with nothing under it is worse than no heading. */
  it('has a page for every section it offers', () => {
    for (const section of SERVER_SECTIONS) {
      expect(pagesOf(section.id).length, `${section.id} is empty`).toBeGreaterThan(0)
    }
  })

  it('keeps every page reachable from exactly one section', () => {
    const listed = SERVER_SECTIONS.flatMap((section) => pagesOf(section.id))

    expect(listed).toHaveLength(SERVER_PAGES.length)
  })

  it('labels every section in both locales', () => {
    const de = JSON.parse(readFileSync('src/i18n/locales/de.json', 'utf8'))
    const en = JSON.parse(readFileSync('src/i18n/locales/en.json', 'utf8'))

    for (const section of SERVER_SECTIONS) {
      const key = section.label.replace('nav.', '')

      expect(de.nav[key], `${section.label} missing from de`).toBeTruthy()
      expect(en.nav[key], `${section.label} missing from en`).toBeTruthy()
    }
  })
})
