import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

import { INI_LABELS, SANDBOX_LABELS } from './labels'

type Schema = {
  ini: Record<string, { labels?: { DE: string | null; EN: string | null } | null }>
  sandbox: Record<string, { labels?: { DE: string | null; EN: string | null } | null }>
}

const schema: Schema = JSON.parse(
  readFileSync('../backend/tests/Fixtures/config-schema.json', 'utf8'),
)

/**
 * The panel names what the game does not.
 *
 * A row falling back to its technical key is a riddle with the answer
 * printed underneath, so every option the *base game* defines has a
 * name from one side or the other. A mod's option cannot be in any
 * table and keeps its key deliberately — that is honest, and the row
 * marks it as a mod's.
 */
describe('the display names', () => {
  it('names every INI option, since the game names none', () => {
    const missing = Object.keys(schema.ini).filter((key) => INI_LABELS[key] === undefined)

    expect(missing, `INI options with no name: ${missing.join(', ')}`).toEqual([])
  })

  it('names every sandbox option the game leaves untranslated', () => {
    const missing = Object.keys(schema.sandbox).filter(
      (key) =>
        (schema.sandbox[key].labels?.EN ?? null) === null && SANDBOX_LABELS[key] === undefined,
    )

    expect(missing, `sandbox options with no name: ${missing.join(', ')}`).toEqual([])
  })

  /** A table entry for an option that no longer exists is dead weight. */
  it('has no entry for an option the game does not define', () => {
    const strayIni = Object.keys(INI_LABELS).filter((key) => schema.ini[key] === undefined)
    const straySandbox = Object.keys(SANDBOX_LABELS).filter(
      (key) => schema.sandbox[key] === undefined,
    )

    expect(strayIni, `INI names for absent options: ${strayIni.join(', ')}`).toEqual([])
    expect(straySandbox, `sandbox names for absent options: ${straySandbox.join(', ')}`).toEqual([])
  })

  /** Two options sharing a name cannot be told apart in a list. */
  it('gives every option a name of its own, in both languages', () => {
    for (const [table, name] of [
      [INI_LABELS, 'INI'],
      [SANDBOX_LABELS, 'sandbox'],
    ] as const) {
      for (const language of ['de', 'en'] as const) {
        const seen = new Map<string, string>()

        for (const [key, entry] of Object.entries(table)) {
          const label = entry[language]
          const first = seen.get(label)

          expect(first, `${name}/${language}: "${label}" is both ${first} and ${key}`).toBeUndefined()

          seen.set(label, key)
        }
      }
    }
  })

  /** These sit above a control, so they are labels rather than prose. */
  it('keeps every name short and without trailing punctuation', () => {
    for (const [key, entry] of Object.entries({ ...INI_LABELS, ...SANDBOX_LABELS })) {
      for (const language of ['de', 'en'] as const) {
        const label = entry[language]

        expect(label.length, `${key}/${language} is empty`).toBeGreaterThan(0)
        expect(label, `${key}/${language} ends in punctuation`).not.toMatch(/[.:!?]$/)
        expect(
          label.split(' ').length,
          `${key}/${language} reads as a sentence: "${label}"`,
        ).toBeLessThanOrEqual(6)
      }
    }
  })

  /** German text in this project carries its diacritics. */
  it('does not write German with ASCII substitutions', () => {
    for (const [key, entry] of Object.entries({ ...INI_LABELS, ...SANDBOX_LABELS })) {
      expect(entry.de, `${key} looks like an ASCII substitution`).not.toMatch(
        /\b(?:fuer|ueber|oeffnen|schliessen|groesse|moeglich|waehlen|zuruecksetzen)\b/i,
      )
    }
  })
})
