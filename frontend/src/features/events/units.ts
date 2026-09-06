/**
 * Printing a number with the unit it is in.
 *
 * A range reading "0–120" beside one reading "0–100" tells nobody that
 * the first is km/h and the second a percentage — the same confusion the
 * wind control had, moved from the value to its label. The unit is
 * declared once by the field in `EventCatalogue` and printed here, so no
 * page has to guess it from the action's name.
 *
 * It is printed at the value the reader changes, not also on the range
 * beside it: "0–120 km/h" above a field already reading "95 km/h" states
 * the unit twice, which is noise rather than clarity.
 */

/** Units that read as part of the number rather than a word after it. */
const TIGHT = new Set(['%', '°C'])

export function withUnit(value: number | string, unit?: string): string {
  if (unit === undefined || unit === '') {
    return String(value)
  }

  return TIGHT.has(unit) ? `${value}${unit}` : `${value} ${unit}`
}

/**
 * A range, with the unit on both ends.
 *
 * "−30–40" is unreadable: the dash between the numbers and the minus in
 * front of the first are the same stroke, so the eye cannot find the
 * boundary. Stating the unit on each end separates them — "−30 °C – 40 °C"
 * — and a range whose numbers are all positive keeps the compact form.
 */
export function formatRange(min: number, max: number, unit?: string): string {
  if (unit === undefined || unit === '') {
    return `${min}–${max}`
  }

  return min < 0
    ? `${withUnit(min, unit)} – ${withUnit(max, unit)}`
    : withUnit(`${min}–${max}`, unit)
}
