import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

import { BARE_REASON_KEYS, looksLikeKey, reasonKey } from './reason'

/**
 * The reason column holds two different things.
 *
 * The backend records `players.joined` for a join and `banExpired` for a
 * lapsed ban — translation keys — while a kick carries whatever the
 * operator typed. Both land in the same column, and the history showed
 * the raw keys until this told them apart.
 */
describe('telling a recorded key from typed prose', () => {
  it('recognises the keys the backend writes', () => {
    expect(reasonKey('players.joined')).toBe('players.joined')
    expect(reasonKey('players.left')).toBe('players.left')
    expect(reasonKey('banExpired')).toBe('players.banExpired')
  })

  /** Anything a person typed stays exactly as typed. */
  it('leaves prose alone', () => {
    for (const prose of [
      'Rude in chat',
      'griefing the safehouse',
      'Base.Plank x30',
      'setTemperature=-5',
      'weil er nicht zuhört',
      '',
    ]) {
      expect(reasonKey(prose), prose).toBeNull()
    }
  })

  /** A dotted path is the shape, not merely a full stop somewhere. */
  it('does not mistake a sentence for a key', () => {
    expect(looksLikeKey('He left. Then came back.')).toBe(false)
    expect(looksLikeKey('players joined')).toBe(false)
    expect(looksLikeKey('Players.Joined')).toBe(false)
  })

  /**
   * Every key the backend actually writes must resolve, or the history
   * falls back to showing the key itself.
   */
  it('has a translation for every recorded key, in both locales', () => {
    const de = JSON.parse(readFileSync('src/i18n/locales/de.json', 'utf8'))
    const en = JSON.parse(readFileSync('src/i18n/locales/en.json', 'utf8'))

    const resolve = (locale: Record<string, unknown>, key: string): unknown =>
      key.split('.').reduce<unknown>(
        (node, part) =>
          typeof node === 'object' && node !== null
            ? (node as Record<string, unknown>)[part]
            : undefined,
        locale,
      )

    for (const reason of ['players.joined', 'players.left', ...BARE_REASON_KEYS]) {
      const key = reasonKey(reason) as string

      expect(resolve(de, key), `${key} missing from de`).toBeTruthy()
      expect(resolve(en, key), `${key} missing from en`).toBeTruthy()
    }
  })

  /**
   * The bare ones come from the backend, so a new one there has to be
   * named here as well — the pattern cannot recognise a lone word
   * without also catching typed prose.
   */
  it('names every bare reason the backend writes', () => {
    const sources = [
      '../backend/src/Server/Players/ExpiredBanLifter.php',
      '../backend/src/Server/Players/RosterWatcher.php',
    ]
      .map((path) => readFileSync(path, 'utf8'))
      .join('\n')

    // A reason passed as a bare quoted camelCase word, which is what
    // 'banExpired' is.
    const bare = [...sources.matchAll(/'([a-z][a-zA-Z0-9]*)',\s*\$reply/g)].map(
      (match) => match[1],
    )

    for (const reason of bare) {
      expect(BARE_REASON_KEYS, `${reason} is written but not named here`).toContain(reason)
    }
  })
})
