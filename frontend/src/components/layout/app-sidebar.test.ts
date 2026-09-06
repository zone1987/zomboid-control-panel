import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

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
