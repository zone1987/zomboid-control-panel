import { useEffect, useState } from 'react'
import { registerSW } from 'virtual:pwa-register'

type Update = {
  /** A newer build is on the server and waiting to take over. */
  ready: boolean
  apply: () => void
}

/**
 * Watches for a newer build of the panel itself.
 *
 * Prompted rather than applied silently: a reload in the middle of typing
 * a ban reason would lose it, so the operator decides when to take it.
 */
export function useAppUpdate(): Update {
  const [ready, setReady] = useState(false)
  const [apply, setApply] = useState<() => void>(() => () => undefined)

  useEffect(() => {
    const update = registerSW({
      immediate: true,
      onNeedRefresh: () => setReady(true),
    })

    setApply(() => () => {
      setReady(false)
      void update(true)
    })
  }, [])

  return { ready, apply }
}
