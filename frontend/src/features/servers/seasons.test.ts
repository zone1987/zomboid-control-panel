import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

import { seasonKey } from './seasons'

/**
 * The game reports "Early Summer"; the panel shows "Frühsommer".
 *
 * The climate page shipped showing the raw English while the world strip
 * beside it showed the translation — the same fact in two languages on
 * one screen — because the key function was private to the strip.
 */
describe('the season key', () => {
  it('turns the game own wording into a translation key', () => {
    expect(seasonKey('Early Summer')).toBe('earlySummer')
    expect(seasonKey('Summer')).toBe('summer')
    expect(seasonKey('LATE WINTER')).toBe('lateWinter')
    expect(seasonKey('  Early  Spring  ')).toBe('earlySpring')
  })

  /** Every season the game has must have a German and an English name. */
  it('resolves every season the game reports, in both locales', () => {
    const seasons = [
      'Early Spring',
      'Spring',
      'Late Spring',
      'Early Summer',
      'Summer',
      'Late Summer',
      'Early Autumn',
      'Autumn',
      'Late Autumn',
      'Early Winter',
      'Winter',
      'Late Winter',
    ]

    const de = JSON.parse(readFileSync('src/i18n/locales/de.json', 'utf8'))
    const en = JSON.parse(readFileSync('src/i18n/locales/en.json', 'utf8'))

    for (const season of seasons) {
      const key = seasonKey(season)

      expect(de.world.seasonName[key], `${season} missing from de`).toBeTruthy()
      expect(en.world.seasonName[key], `${season} missing from en`).toBeTruthy()
    }
  })

  /**
   * Every page showing a season has to translate it. Asserted against the
   * sources, because a page printing the raw value looks right in English
   * and wrong in German — which is how it got shipped once.
   */
  it('is used by every page that shows a season', () => {
    for (const page of [
      'src/features/servers/world-strip.tsx',
      'src/features/events/climate-page.tsx',
    ]) {
      const source = readFileSync(page, 'utf8')

      expect(source, `${page} shows a season without translating it`).toContain('seasonKey(')
    }
  })
})
