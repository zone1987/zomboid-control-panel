import { describe, expect, it } from 'vitest'

import { crumbsFor } from './breadcrumbs'
import { SERVER_PAGES } from './server-pages'

const SERVER_ID = '9f1d2b7c-1c4e-4b8a-9f2e-3a5c6d7e8f90'

describe('deriving breadcrumbs from a path', () => {
  it('shows the dashboard alone at the root', () => {
    expect(crumbsFor('/')).toEqual([{ label: 'nav.dashboard' }])
  })

  it('shows one crumb for a top level page', () => {
    expect(crumbsFor('/servers')).toEqual([{ label: 'nav.servers' }])
    expect(crumbsFor('/settings')).toEqual([{ label: 'nav.settings' }])
  })

  /**
   * An unlisted page falls back to the dashboard label, so a page added
   * without its title reads as "Overview" and nothing complains.
   */
  it('names the credits page', () => {
    expect(crumbsFor('/credits')).toEqual([{ label: 'nav.credits' }])
  })

  it('links the root back to the section on a server sub page', () => {
    expect(crumbsFor(`/servers/${SERVER_ID}/players`)).toEqual([
      { label: 'nav.servers', to: '/servers' },
      { label: 'nav.players' },
    ])
  })

  it('reads three levels on an event category', () => {
    expect(crumbsFor(`/servers/${SERVER_ID}/events/weather`)).toEqual([
      { label: 'nav.servers', to: '/servers' },
      { label: 'nav.events', to: `/servers/${SERVER_ID}/events` },
      { label: 'events.categories.weather' },
    ])
  })

  /** A page with no children must not grow a third crumb from a stray path. */
  it('ignores a fourth segment under a page with no children', () => {
    expect(crumbsFor(`/servers/${SERVER_ID}/players/anything`)).toEqual([
      { label: 'nav.servers', to: '/servers' },
      { label: 'nav.players' },
    ])
  })

  it('ignores a fourth segment that is not a known child', () => {
    expect(crumbsFor(`/servers/${SERVER_ID}/events/nowhere`)).toEqual([
      { label: 'nav.servers', to: '/servers' },
      { label: 'nav.events' },
    ])
  })

  it('falls back to the dashboard label for an unknown root', () => {
    expect(crumbsFor('/nowhere')).toEqual([{ label: 'nav.dashboard' }])
  })

  it('shows only the root crumb for an unknown sub page', () => {
    expect(crumbsFor(`/servers/${SERVER_ID}/nowhere`)).toEqual([
      { label: 'nav.servers' },
    ])
  })

  it('shows only the root crumb for a server without a sub page', () => {
    expect(crumbsFor(`/servers/${SERVER_ID}`)).toEqual([{ label: 'nav.servers' }])
  })

  it('gives every page in the navigation table a crumb of its own', () => {
    for (const page of SERVER_PAGES) {
      const crumbs = crumbsFor(`/servers/${SERVER_ID}/${page.path}`)

      expect(crumbs, `"${page.path}" has no sub page crumb`).toHaveLength(2)
      expect(crumbs[1]).toEqual({ label: page.label })
      expect(crumbs[0]).toEqual({ label: 'nav.servers', to: '/servers' })
    }
  })
})
