import { describe, expect, it } from 'vitest'

import { isGameTime } from './world-strip'

const complete = { year: 1993, month: 6, day: 30, hour: 16, minute: 16, daysSurvived: 0 }

describe('reading the in-game clock the bridge wrote', () => {
  it('accepts a complete reading', () => {
    expect(isGameTime(complete)).toBe(true)
  })

  it('refuses nothing at all', () => {
    expect(isGameTime(null)).toBe(false)
    expect(isGameTime(undefined)).toBe(false)
  })

  // The bridge rewrites its file in place, so a read can catch it
  // half-written. Formatting a date off one of those threw
  // "Cannot read properties of undefined" and took the page down.
  it('refuses a reading caught mid-write, one field at a time', () => {
    for (const field of ['year', 'month', 'day', 'hour', 'minute'] as const) {
      const partial = { ...complete }
      delete (partial as Partial<typeof complete>)[field]

      expect(isGameTime(partial as typeof complete)).toBe(false)
    }
  })

  it('refuses a field that arrived as something other than a number', () => {
    expect(isGameTime({ ...complete, month: Number.NaN })).toBe(false)
    expect(isGameTime({ ...complete, month: '6' as unknown as number })).toBe(false)
    expect(isGameTime({ ...complete, day: null as unknown as number })).toBe(false)
  })

  it('accepts an empty object as nothing to show, not as a clock', () => {
    expect(isGameTime({} as typeof complete)).toBe(false)
  })
})
