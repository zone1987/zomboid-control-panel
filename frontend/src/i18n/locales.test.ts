import { describe, expect, it } from 'vitest'

import de from './locales/de.json'
import en from './locales/en.json'

type Table = Record<string, unknown>

/** Every leaf key, dotted, so two tables can be compared as sets. */
function keysOf(table: Table, prefix = ''): string[] {
  return Object.entries(table).flatMap(([key, value]) => {
    const path = prefix === '' ? key : `${prefix}.${key}`

    return typeof value === 'object' && value !== null && !Array.isArray(value)
      ? keysOf(value as Table, path)
      : [path]
  })
}

/**
 * English ships with the entry chunk and is the fallback; German loads
 * separately. A key present in one and missing in the other therefore
 * shows as either a raw key or an untranslated string, depending on the
 * direction -- so both directions are checked.
 */
describe('translation tables', () => {
  const german = keysOf(de as Table)
  const english = keysOf(en as Table)

  it('has the same keys in both languages', () => {
    expect(german.filter((key) => !english.includes(key))).toEqual([])
    expect(english.filter((key) => !german.includes(key))).toEqual([])
  })

  it('leaves no value empty', () => {
    for (const [name, table] of [
      ['de', de],
      ['en', en],
    ] as const) {
      const empty = keysOf(table as Table).filter((key) => {
        const value = key
          .split('.')
          .reduce<unknown>((node, part) => (node as Table)?.[part], table)

        return typeof value === 'string' && value.trim() === ''
      })

      expect(empty, `empty values in ${name}.json`).toEqual([])
    }
  })

  /**
   * The interpolations have to match, or a language silently drops a
   * value: "{{count}} players" against "Spieler" loses the number.
   */
  it('uses the same interpolations in both languages', () => {
    const placeholders = (value: unknown): string[] =>
      typeof value === 'string' ? [...value.matchAll(/\{\{(\w+)\}\}/g)].map((m) => m[1]).sort() : []

    const read = (table: unknown, key: string): unknown =>
      key.split('.').reduce<unknown>((node, part) => (node as Table)?.[part], table)

    for (const key of english) {
      expect(placeholders(read(de, key)), `interpolations differ for ${key}`).toEqual(
        placeholders(read(en, key)),
      )
    }
  })
})
