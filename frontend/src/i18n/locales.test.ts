import { describe, expect, it } from 'vitest'

import { SUPPORTED_LANGUAGES, LANGUAGE_NAMES, UNREVIEWED_LANGUAGES } from './config'
import de from './locales/de.json'
import en from './locales/en.json'
import es from './locales/es.json'
import fr from './locales/fr.json'
import italian from './locales/it.json'
import pl from './locales/pl.json'
import ru from './locales/ru.json'

type Table = Record<string, unknown>

const TABLES: Record<string, Table> = { de, en, es, fr, it: italian, pl, ru }

const FLAT: Record<string, Map<string, unknown>> = Object.fromEntries(
  Object.entries(TABLES).map(([language, table]) => [language, flatten(table)]),
)

/** i18next resolves a plural by suffix, so these are not missing keys. */
const PLURAL_SUFFIX = /_(zero|one|two|few|many|other)$/

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
 * Values by their dotted path, built by walking rather than by
 * splitting: a permission key contains a dot of its own
 * (`roles.permissions.chat.read` is three levels, not four), so
 * addressing into the tree finds nothing.
 */
function flatten(table: Table, prefix = ''): Map<string, unknown> {
  const out = new Map<string, unknown>()

  for (const [key, value] of Object.entries(table)) {
    const path = prefix === '' ? key : `${prefix}.${key}`

    if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
      for (const [k, v] of flatten(value as Table, path)) {
        out.set(k, v)
      }
    } else {
      out.set(path, value)
    }
  }

  return out
}

function placeholders(value: unknown): string[] {
  return typeof value === 'string'
    ? [...value.matchAll(/\{\{(\w+)\}\}/g)].map((m) => m[1]).sort()
    : []
}

/**
 * English ships with the entry chunk and is the fallback; every other
 * language loads separately. A key missing from one of them shows as an
 * English string in a Polish session -- or, the other way round, as a
 * raw key nobody can read.
 */
describe('translation tables', () => {
  const english = keysOf(en as Table)
  const others = SUPPORTED_LANGUAGES.filter((language) => language !== 'en')

  it('ships a table for every supported language', () => {
    expect(Object.keys(TABLES).sort()).toEqual([...SUPPORTED_LANGUAGES].sort())
  })

  it('names every supported language in its own language', () => {
    for (const language of SUPPORTED_LANGUAGES) {
      expect(LANGUAGE_NAMES[language], `no name for ${language}`).toBeTruthy()
    }
  })

  it('marks as unreviewed only languages that exist', () => {
    for (const language of UNREVIEWED_LANGUAGES) {
      expect(SUPPORTED_LANGUAGES, `${language} is not supported`).toContain(language)
    }

    // English and German were written by hand; claiming otherwise would
    // be as wrong as hiding the rest.
    expect(UNREVIEWED_LANGUAGES).not.toContain('en')
    expect(UNREVIEWED_LANGUAGES).not.toContain('de')
  })

  it.each(others)('%s translates every key English has', (language) => {
    const missing = english.filter((key) => !FLAT[language].has(key))

    expect(missing, `${missing.length} keys missing from ${language}.json`).toEqual([])
  })

  /**
   * The other direction, with one exception: Polish and Russian need
   * `_few` and `_many`, which English does not have. Anything else extra
   * is a key nothing reads -- usually a typo in the key name, which
   * leaves the real one falling back to English.
   */
  it.each(others)('%s adds no key English does not have, beyond plural forms', (language) => {
    const extra = keysOf(TABLES[language]).filter(
      (key) => !english.includes(key) && !PLURAL_SUFFIX.test(key),
    )

    expect(extra, `keys in ${language}.json that nothing reads`).toEqual([])
  })

  it.each(SUPPORTED_LANGUAGES)('%s leaves no value empty', (language) => {
    const empty = keysOf(TABLES[language]).filter((key) => {
      const value = FLAT[language].get(key)

      return typeof value === 'string' && value.trim() === ''
    })

    expect(empty, `empty values in ${language}.json`).toEqual([])
  })

  /**
   * The interpolations have to match, or a language silently drops a
   * value: "{{count}} players" against "Spieler" loses the number. A
   * placeholder invented by a translator is worse -- i18next prints it
   * verbatim, braces and all.
   */
  it.each(others)('%s uses the same interpolations as English', (language) => {
    for (const key of english) {
      const theirs = FLAT[language].get(key)

      if (!FLAT[language].has(key)) {
        continue // Reported by the missing-keys test; not twice.
      }

      const want = placeholders(FLAT.en.get(key))
      const got = placeholders(theirs)

      // `_one` is the exception. English spells the number out -- "One
      // value" -- while Polish and Russian resolve `_one` for 21, 31 and
      // 101 as well, where writing "one" would be false. Adding
      // {{count}} there is the correct translation, so the rule for
      // `_one` is only that nothing is invented or lost.
      if (key.endsWith('_one')) {
        expect(
          got.filter((p) => p !== 'count'),
          `${key} in ${language} invented a placeholder`,
        ).toEqual(want.filter((p) => p !== 'count'))

        continue
      }

      expect(got, `interpolations differ for ${key} in ${language}`).toEqual(want)
    }
  })

  /**
   * A plural key needs every form its language uses, or i18next falls
   * through to English for the counts it cannot resolve -- "5 wartości"
   * becoming "5 values" in the middle of a Polish sentence.
   */
  it.each(others)('%s carries every plural form its language needs', (language) => {
    const required: Record<string, string[]> = {
      de: ['one', 'other'],
      es: ['one', 'other'],
      fr: ['one', 'other'],
      it: ['one', 'other'],
      pl: ['one', 'few', 'many', 'other'],
      ru: ['one', 'few', 'many', 'other'],
    }

    const bases = [...new Set(english.filter((k) => PLURAL_SUFFIX.test(k)).map((k) => k.replace(PLURAL_SUFFIX, '')))]

    for (const base of bases) {
      for (const form of required[language] ?? ['one', 'other']) {
        expect(
          FLAT[language].get(`${base}_${form}`),
          `${language}.json is missing ${base}_${form}`,
        ).toBeTypeOf('string')
      }
    }
  })

  /**
   * A count that loses its number reads as a bare noun. Only the plural
   * forms that actually vary need it -- `_one` is often "One value" with
   * the number spelled out, which is right.
   */
  it.each(others)('%s keeps the count in the plural forms that need it', (language) => {
    const bases = [...new Set(english.filter((k) => PLURAL_SUFFIX.test(k)).map((k) => k.replace(PLURAL_SUFFIX, '')))]

    for (const base of bases) {
      const englishOther = FLAT.en.get(`${base}_other`)

      if (!placeholders(englishOther).includes('count')) {
        continue
      }

      const theirs = FLAT[language].get(`${base}_other`)

      expect(placeholders(theirs), `${base}_other in ${language} lost {{count}}`).toContain('count')
    }
  })
})
