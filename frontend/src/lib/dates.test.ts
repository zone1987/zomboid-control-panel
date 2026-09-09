import { describe, expect, it } from 'vitest'

import { formatDate, formatDateTime } from './dates'

describe('showing a date', () => {
  /** The user asked for this: 9.9.2026 reads worse than 09.09.2026. */
  it('pads the day and the month with a leading zero', () => {
    expect(formatDate('2026-09-09T10:00:00Z', 'de-DE')).toBe('09.09.2026')
  })

  it('keeps each language’s own order', () => {
    expect(formatDate('2026-09-09T10:00:00Z', 'en-GB')).toBe('09/09/2026')
    expect(formatDate('2026-09-09T10:00:00Z', 'en-US')).toBe('09/09/2026')
  })

  it('takes a Date as readily as a string', () => {
    expect(formatDate(new Date('2026-01-02T00:00:00Z'), 'de-DE')).toBe('02.01.2026')
  })

  /** Empty, not "Invalid Date": the interface shows nothing instead. */
  it('says nothing for something that is not a date', () => {
    expect(formatDate('not a date', 'de-DE')).toBe('')
  })

  it('pads the time as well', () => {
    expect(formatDateTime('2026-09-09T08:05:00Z', 'de-DE')).toContain('09.09.2026')
  })
})
