import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

import { DAY_MARKS } from './day-marks'

/**
 * The four quick picks name hours a person thinks in. A mark with no
 * label renders its key, which reads as a bug on screen.
 */
describe('the day marks', () => {
  it('names four hours of the day, each within it', () => {
    expect(DAY_MARKS).toHaveLength(4)

    for (const mark of DAY_MARKS) {
      expect(mark.hour, mark.key).toBeGreaterThanOrEqual(0)
      expect(mark.hour, mark.key).toBeLessThan(24)
    }
  })

  it('names each hour once', () => {
    const hours = DAY_MARKS.map((mark) => mark.hour)

    expect(new Set(hours).size).toBe(hours.length)
  })

  it('labels every mark in both locales', () => {
    const de = JSON.parse(readFileSync('src/i18n/locales/de.json', 'utf8'))
    const en = JSON.parse(readFileSync('src/i18n/locales/en.json', 'utf8'))

    for (const mark of DAY_MARKS) {
      expect(de.events.dayMarks[mark.key], `${mark.key} missing from de`).toBeTruthy()
      expect(en.events.dayMarks[mark.key], `${mark.key} missing from en`).toBeTruthy()
    }
  })
})
