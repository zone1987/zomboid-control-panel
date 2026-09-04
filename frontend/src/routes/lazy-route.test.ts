import { describe, expect, it } from 'vitest'

import { looksLikeStaleChunk } from './lazy-route'

/**
 * A build replaces every chunk name, so a browser that has had the page
 * open across a deploy asks for a file that no longer exists. The only
 * signal is the message, and each browser words it differently.
 */
describe('recognising a chunk that no longer exists', () => {
  it('reads the wording Chrome and Safari use', () => {
    expect(
      looksLikeStaleChunk(
        new TypeError(
          'Failed to fetch dynamically imported module: https://example.test/app/assets/console-page-BsF_mUT_.js',
        ),
      ),
    ).toBe(true)
  })

  it('reads the wording Firefox uses', () => {
    expect(
      looksLikeStaleChunk(new TypeError('error loading dynamically imported module')),
    ).toBe(true)
  })

  it('reads the wording an older Safari uses', () => {
    expect(
      looksLikeStaleChunk(new TypeError('Importing a module script failed.')),
    ).toBe(true)
  })

  /** Reloading would hide a real fault rather than fix it. */
  it('is not any other failure', () => {
    expect(looksLikeStaleChunk(new Error('Cannot read properties of undefined'))).toBe(false)
    expect(looksLikeStaleChunk(new Error('NetworkError when attempting to fetch resource'))).toBe(
      false,
    )
  })

  it('is not something that is not an error at all', () => {
    expect(looksLikeStaleChunk('failed to fetch dynamically imported module')).toBe(false)
    expect(looksLikeStaleChunk(null)).toBe(false)
    expect(looksLikeStaleChunk(undefined)).toBe(false)
  })
})
