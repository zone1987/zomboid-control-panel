import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

/**
 * Neither provider button may be rendered unconditionally.
 *
 * A button for a provider nobody has linked can only answer "no such
 * account", which is the one failure the login page has no way to
 * explain. The endpoint decides; the page must ask it.
 *
 * Read from the source rather than rendered: this is about the page's
 * wiring, and a button added later must not quietly become permanent.
 */
describe('the login page offers only usable providers', () => {
  const source = readFileSync(new URL('./login-page.tsx', import.meta.url), 'utf8')

  it('asks the endpoint which providers to offer', () => {
    expect(source).toContain('getLoginProviders')
  })

  for (const provider of ['google', 'steam'] as const) {
    it(`gates the ${provider} button on the answer`, () => {
      const button = new RegExp(`href="/api/connect/${provider}"`)

      expect(button.test(source), `no ${provider} button found at all`).toBe(true)

      // The guard has to sit above the button, not merely somewhere in
      // the file: `providers?.x === true &&` immediately preceding it.
      const guarded = new RegExp(
        `providers\\?\\.${provider} === true &&[\\s\\S]{0,240}?href="/api/connect/${provider}"`,
      )

      expect(guarded.test(source), `the ${provider} button is not gated`).toBe(true)
    })
  }

  it('hides the divider when nothing follows it', () => {
    expect(source).toMatch(/passkeysSupported \|\| providers\?\.google === true \|\| providers\?\.steam === true/)
  })
})
