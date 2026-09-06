/**
 * The game names its seasons in English, and the panel translates them.
 *
 * Shared rather than private to the world strip, because the climate
 * page shows the same value and showed it raw — a page reading "Early
 * Summer" beside one reading "Frühsommer" is the same fact in two
 * languages on one screen.
 */

/** "Early Summer" becomes "earlySummer". */
export function seasonKey(season: string): string {
  return season
    .trim()
    .split(/\s+/)
    .map((word, index) =>
      index === 0
        ? word.toLowerCase()
        : word.charAt(0).toUpperCase() + word.slice(1).toLowerCase(),
    )
    .join('')
}
