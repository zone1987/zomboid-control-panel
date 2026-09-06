/**
 * A recorded reason, which is sometimes a translation key.
 *
 * The backend writes `players.joined` for a join and `banExpired` for a
 * lapsed ban — keys, not prose — while a kick carries whatever the
 * operator typed. Both land in the same column, so the renderer has to
 * tell them apart, and the history showed raw keys until it did.
 *
 * The test is deliberately narrow: a dotted lower-camel path with no
 * spaces is a key, anything a person typed is not.
 */
export function looksLikeKey(reason: string): boolean {
  return /^[a-z][A-Za-z0-9]*(\.[a-zA-Z][A-Za-z0-9]*)+$/.test(reason)
}

/**
 * The reasons recorded as a bare word rather than a dotted key.
 *
 * `ExpiredBanLifter` writes 'banExpired' with no namespace, so the test
 * above cannot recognise it — naming them is cheaper than making the
 * pattern loose enough to catch a typed word by accident.
 */
export const BARE_REASON_KEYS = ['banExpired'] as const

/** The i18n key for a reason, or null when it is prose. */
export function reasonKey(reason: string): string | null {
  if ((BARE_REASON_KEYS as readonly string[]).includes(reason)) {
    return `players.${reason}`
  }

  return looksLikeKey(reason) ? reason : null
}
