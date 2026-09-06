import { describe, expect, it } from 'vitest'

import { permissionForPath } from './page-permission'
import { SERVER_PAGES } from '@/components/layout/server-pages'

/**
 * The per-page permissions used to be enforced only in the sidebar, so a
 * moderator with `players.view` could reach the console by typing its
 * URL: the entry was hidden, the route was not.
 *
 * Read from the same table the navigation reads, so the two cannot
 * drift. A second list of route permissions would eventually disagree
 * with the first, and the disagreement would be the hole.
 */
describe('the permission a server page needs', () => {
  const id = '01a06d21-0424-7894-a2ac-14d1408a2430'

  it('resolves every page the sidebar knows', () => {
    for (const page of SERVER_PAGES) {
      expect(permissionForPath(`/servers/${id}/${page.path}`), page.path).toBe(page.permission)
    }
  })

  /** The console is the one that mattered: it runs arbitrary commands. */
  it('gates the console behind its own permission', () => {
    expect(permissionForPath(`/servers/${id}/console`)).toBe('console.use')
  })

  /**
   * A child page carries no permission of its own — there is one event
   * permission and the parent gates the subtree — so it has to resolve
   * through its parent rather than to null.
   */
  it('resolves a child page through its parent', () => {
    for (const child of ['weather', 'climate', 'actions', 'world', 'sounds', 'zombies']) {
      expect(permissionForPath(`/servers/${id}/events/${child}`), child).toBe('events.trigger')
    }
  })

  /** Anything outside a server path is not this guard's business. */
  it('leaves paths it does not own alone', () => {
    for (const path of ['/', '/settings', '/users', '/profile', '/credits', `/servers`]) {
      expect(permissionForPath(path), path).toBeNull()
    }
  })

  /** An unknown segment is not silently permitted or silently blocked. */
  it('returns null for a page it has never heard of', () => {
    expect(permissionForPath(`/servers/${id}/nonsense`)).toBeNull()
  })
})
