import { useEffect, useState } from 'react'

/**
 * The value, once it has stopped changing for a while.
 *
 * For a search that asks a remote service: firing on every keystroke
 * would send eight requests for one word, and Steam does not document
 * its rate limit.
 *
 * The effect is the right tool here despite rule 10g2 — this is a timer
 * over time, not server data copied into state, and there is no way to
 * derive "has been still for 400ms" during render.
 */
export function useDebounced<T>(value: T, delayMs = 400): T {
  const [settled, setSettled] = useState(value)

  useEffect(() => {
    const timer = window.setTimeout(() => setSettled(value), delayMs)

    return () => window.clearTimeout(timer)
  }, [value, delayMs])

  return settled
}
