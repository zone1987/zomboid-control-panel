import { useCallback, useEffect, useRef, useState } from 'react'

import { deploymentProgress, type DeploymentProgress } from './settings'

/** Phases a reader cares about while their own panel is being replaced. */
export type WatchPhase =
  | 'idle'
  | 'deploying'
  | 'restarting'
  | 'reloading'
  | 'failed'
  | 'unknown'

export type DeploymentWatch = {
  phase: WatchPhase
  reported: string | null
  start: (deploymentUuid: string | null) => void
}

const POLL_MS = 3000
const HEALTH_POLL_MS = 2000

/**
 * The panel restarts partway through its own deployment, so the
 * browser -- not the backend -- follows it.
 *
 * A request that fails while deploying is expected rather than an
 * error: it is the container going away. The phase moves to
 * `restarting` and `/api/health` is asked until it answers, then the
 * page reloads so the new version is what the reader sees.
 */
export function useDeploymentWatch(): DeploymentWatch {
  const [phase, setPhase] = useState<WatchPhase>('idle')
  const [reported, setReported] = useState<string | null>(null)
  const timer = useRef<number | null>(null)
  const stopped = useRef(false)

  useEffect(
    () => () => {
      stopped.current = true

      if (timer.current !== null) {
        window.clearTimeout(timer.current)
      }
    },
    [],
  )

  const waitForThePanel = useCallback(() => {
    setPhase('restarting')

    const ask = async () => {
      if (stopped.current) {
        return
      }

      try {
        const response = await fetch('/api/health', { cache: 'no-store' })

        if (response.ok) {
          setPhase('reloading')
          window.location.reload()

          return
        }
      } catch {
        // Still down; that is what we are waiting through.
      }

      timer.current = window.setTimeout(ask, HEALTH_POLL_MS)
    }

    void ask()
  }, [])

  const start = useCallback(
    (deploymentUuid: string | null) => {
      stopped.current = false
      setReported(null)

      // Without a uuid there is nothing to follow, so the only signal
      // left is the panel going away and coming back.
      if (deploymentUuid === null) {
        waitForThePanel()

        return
      }

      setPhase('deploying')

      const ask = async () => {
        if (stopped.current) {
          return
        }

        let progress: DeploymentProgress | null = null

        try {
          progress = await deploymentProgress(deploymentUuid)
        } catch {
          // The panel itself is going down mid-deployment; that is the
          // expected middle of this, not a failure.
          waitForThePanel()

          return
        }

        setReported(progress.reported ?? null)

        if (progress.state === 'failed' || progress.state === 'cancelled') {
          setPhase('failed')

          return
        }

        if (progress.state === 'finished') {
          waitForThePanel()

          return
        }

        if (progress.state === 'notFound') {
          // Nothing to report on any more; fall back to the panel itself.
          waitForThePanel()

          return
        }

        // Coolify declares no set of status words, so one nobody
        // planned for is shown as such -- and still followed, because
        // not knowing is not the same as being finished.
        setPhase(progress.state === 'unknown' || progress.state === 'unreachable' ? 'unknown' : 'deploying')

        timer.current = window.setTimeout(ask, POLL_MS)
      }

      void ask()
    },
    [waitForThePanel],
  )

  return { phase, reported, start }
}
