import { useMemo, useState } from 'react'

import { outsideBounds, parseInput, type ConfigValue } from './config'

/** One pending edit, held against the value it belongs to. */
type Edit = {
  /** What was typed, kept as text so a half-typed number survives. */
  text: string
  /** The value this edit was started from, to detect a refetch. */
  from: boolean | number | string
}

export type DraftRow = {
  /** What the control shows: the edit if there is one, else the file. */
  shown: boolean | number | string
  /** The parsed value, or null when the text is not one. */
  parsed: boolean | number | string | null
  /** Typed something that is not a value of this type. */
  invalid: boolean
  /** A number the game's own bounds would refuse. */
  outOfBounds: boolean
  /** Differs from what the file holds. */
  changed: boolean
}

/**
 * Collects edits across 270 rows and hands back what to send.
 *
 * Held as `{text, from}` per key rather than copied out of the query,
 * because a query that refetches while somebody is typing would
 * otherwise overwrite the field. When the underlying value changes the
 * edit is dropped — the file moved, and an edit against a value that no
 * longer exists is not an edit anybody meant.
 *
 * The text is kept rather than the parsed number so "0." and "-" stay
 * typeable on the way to "0.5" and "-1".
 */
export function useConfigDraft(values: ConfigValue[]) {
  const [edits, setEdits] = useState<Record<string, Edit>>({})

  const byKey = useMemo(
    () => new Map(values.map((value) => [value.key, value])),
    [values],
  )

  const rows = useMemo(() => rowsFor(values, edits), [values, edits])
  const pending = useMemo(() => pendingFrom(rows), [rows])

  const blocked = useMemo(
    () => [...rows.values()].some((row) => row.invalid || row.outOfBounds),
    [rows],
  )

  return {
    rows,
    pending,
    /** Something is typed that must not be sent, so saving is refused. */
    blocked,
    /** How many would be sent. */
    count: Object.keys(pending).length,
    /**
     * How many rows differ from the file at all, sendable or not.
     *
     * The save bar keys off this rather than off `count`: a row holding
     * only an out-of-bounds number has nothing to send, and a bar that
     * vanishes leaves the operator with no way to discard it.
     */
    touched: [...rows.values()].filter((row) => row.changed).length,
    set: (key: string, text: string) => {
      const value = byKey.get(key)

      if (value === undefined) {
        return
      }

      setEdits((current) => ({ ...current, [key]: { text, from: value.value } }))
    },
    /** After a successful save, or when the operator discards. */
    clear: () => setEdits({}),
    clearKeys: (keys: string[]) =>
      setEdits((current) => {
        const next = { ...current }

        for (const key of keys) {
          delete next[key]
        }

        return next
      }),
  }
}

/**
 * The state of every row, given the file and what has been typed.
 *
 * Pure, so the rules can be tested without a DOM: an edit survives a
 * refetch of the same value, is dropped when the underlying value moved,
 * and never reaches `pending` while it is unusable.
 *
 * @param edits keyed by option, each holding the text and the value it
 *     was started from
 */
export function rowsFor(
  values: ConfigValue[],
  edits: Record<string, { text: string; from: boolean | number | string }>,
): Map<string, DraftRow> {
  const built = new Map<string, DraftRow>()

  for (const value of values) {
    const edit = edits[value.key]

    // An edit against a value that has since changed is not an edit
    // anybody meant: somebody else moved the file underneath.
    const stale = edit !== undefined && !same(edit.from, value.value)

    if (edit === undefined || stale) {
      built.set(value.key, {
        shown: value.value,
        parsed: value.value,
        invalid: false,
        outOfBounds: false,
        changed: false,
      })

      continue
    }

    const parsed = parseInput(value, edit.text)

    built.set(value.key, {
      shown: value.type === 'boolean' ? edit.text === 'true' : edit.text,
      parsed,
      invalid: parsed === null,
      outOfBounds: parsed !== null && outsideBounds(value, parsed),
      changed: parsed === null || !same(parsed, value.value),
    })
  }

  return built
}

/** Only the rows holding a usable value that actually differs. */
export function pendingFrom(rows: Map<string, DraftRow>): Record<string, boolean | number | string> {
  const out: Record<string, boolean | number | string> = {}

  for (const [key, row] of rows) {
    if (row.changed && !row.invalid && !row.outOfBounds && row.parsed !== null) {
      out[key] = row.parsed
    }
  }

  return out
}

/** Compares as the file would: 1 and 1.0 are the same value. */
function same(a: boolean | number | string, b: boolean | number | string): boolean {
  if (typeof a === 'boolean' || typeof b === 'boolean') {
    return a === b
  }

  const left = Number(a)
  const right = Number(b)

  if (!Number.isNaN(left) && !Number.isNaN(right) && String(a).trim() !== '' && String(b).trim() !== '') {
    return Math.abs(left - right) < 0.000001
  }

  return String(a) === String(b)
}
