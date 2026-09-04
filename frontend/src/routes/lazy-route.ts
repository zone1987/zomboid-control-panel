/**
 * Loads a route's code, surviving a deploy that happened meanwhile.
 *
 * Vite puts a content hash in every chunk name, so a new build replaces
 * console-page-BsF_mUT_.js with a differently named file and deletes the
 * old one. A browser that has had the page open since before that build
 * still asks for the old name and gets a 404, which React Router shows
 * as "Failed to fetch dynamically imported module".
 *
 * The page is simply out of date, so it reloads once and comes back with
 * the current file list. The marker in sessionStorage stops that
 * becoming a loop when the chunk is missing for some other reason.
 */
const RELOAD_MARKER = 'zc:chunk-reload'

export function lazyRoute<T extends Record<string, unknown>>(
  load: () => Promise<T>,
  exportName: keyof T,
): () => Promise<{ Component: T[keyof T] }> {
  return async () => {
    try {
      const module = await load()

      // Got there: any earlier reload did its job.
      sessionStorage.removeItem(RELOAD_MARKER)

      return { Component: module[exportName] }
    } catch (error) {
      if (looksLikeStaleChunk(error) && !hasAlreadyReloaded()) {
        sessionStorage.setItem(RELOAD_MARKER, String(Date.now()))
        window.location.reload()

        // Never resolves: the reload is already under way.
        return new Promise<never>(() => undefined)
      }

      throw error
    }
  }
}

/**
 * Whether this looks like a chunk that no longer exists.
 *
 * The message is the only signal browsers give, and each words it
 * differently -- Chrome and Safari say "Failed to fetch dynamically
 * imported module", Firefox "error loading dynamically imported
 * module".
 */
export function looksLikeStaleChunk(error: unknown): boolean {
  if (!(error instanceof Error)) {
    return false
  }

  const message = error.message.toLowerCase()

  return (
    message.includes('dynamically imported module') ||
    message.includes('failed to fetch dynamically') ||
    message.includes('importing a module script failed')
  )
}

/** One reload is a fix; a second in quick succession is a loop. */
function hasAlreadyReloaded(): boolean {
  const at = sessionStorage.getItem(RELOAD_MARKER)

  if (at === null) {
    return false
  }

  return Date.now() - Number.parseInt(at, 10) < 20_000
}
