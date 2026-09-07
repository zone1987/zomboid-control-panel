import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

/**
 * `apiFetch` stringifies the body itself, so a caller must not.
 *
 * Three new calls did it anyway and sent a JSON *string* holding JSON.
 * Symfony's `toArray()` then read nothing, every field came back null,
 * and the response was a 422 blaming the first field it checked — so
 * the error pointed at the payload's contents rather than its shape,
 * and setting a skill or a trait failed from the interface while the
 * same command worked from the console.
 */
describe('the api client contract', () => {
  const client = readFileSync('src/lib/api.ts', 'utf8')

  it('stringifies the body itself', () => {
    expect(client).toMatch(/body instanceof FormData \? body : JSON\.stringify\(body\)/)
  })

  it('has no caller stringifying a body a second time', () => {
    const sources = [
      'src/features/players/players.ts',
      'src/features/players/character.ts',
      'src/features/items/items.ts',
      'src/features/events/events.ts',
    ]

    for (const path of sources) {
      let source: string

      try {
        source = readFileSync(path, 'utf8')
      } catch {
        continue
      }

      expect(
        source,
        `${path} double-encodes a body; pass the object, not JSON.stringify(...)`,
      ).not.toMatch(/body:\s*JSON\.stringify/)
    }
  })
})
