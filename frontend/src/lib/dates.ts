/**
 * Dates with a leading zero, in the reader's own language.
 *
 * `toLocaleDateString` without options drops the zero — German renders
 * 9.9.2026 rather than 09.09.2026 — which makes a column of dates
 * ragged and, at a glance, harder to compare. Asked for by the user.
 */
export function formatDate(value: Date | string | number, locale: string): string {
  const date = value instanceof Date ? value : new Date(value)

  if (Number.isNaN(date.getTime())) {
    return ''
  }

  return date.toLocaleDateString(locale, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

/** The same, with the time, for a log line or a last-seen stamp. */
export function formatDateTime(value: Date | string | number, locale: string): string {
  const date = value instanceof Date ? value : new Date(value)

  if (Number.isNaN(date.getTime())) {
    return ''
  }

  return date.toLocaleString(locale, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}
