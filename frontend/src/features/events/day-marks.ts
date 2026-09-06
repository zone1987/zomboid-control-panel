/**
 * The four hours worth a quick pick, and what they are called.
 *
 * Beside the arc rather than inside it: a file exporting both a
 * component and a constant breaks fast refresh, and this is the half a
 * test wants to read.
 */
export const DAY_MARKS = [
  { hour: 6, key: 'dawn' },
  { hour: 12, key: 'noon' },
  { hour: 18, key: 'dusk' },
  { hour: 0, key: 'midnight' },
] as const

export type DayMark = (typeof DAY_MARKS)[number]
