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

/**
 * Reloads onto the build the server now has, service worker included.
 *
 * `location.reload()` is not enough: the worker keeps answering from
 * its precache, so the panel came back on the old build and then
 * offered "Neue Version" -- a second step after a deployment the panel
 * had just carried out itself.
 *
 * Unregistering rather than `skipWaiting` because the caches have to go
 * too; a waiting worker still serves the old chunk manifest.
 */
export async function reloadOntoTheNewBuild(): Promise<void> {
  try {
    const registrations = await navigator.serviceWorker?.getRegistrations()

    for (const registration of registrations ?? []) {
      await registration.unregister()
    }

    const keys = await caches?.keys()

    for (const key of keys ?? []) {
      await caches.delete(key)
    }
  } catch {
    // A browser that refuses either of those still needs the reload;
    // it simply has no worker to get in the way.
  }

  window.location.reload()
}
