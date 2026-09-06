import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'

import { apiFetch } from '@/lib/api'
import type { VehicleArtwork, VehicleRenderer } from './vehicle-renderer'

type Catalogue = { drawable: Record<string, VehicleArtwork> }

/**
 * Owns the vehicle renderer for as long as the map is open.
 *
 * Held here rather than built per marker: it keeps one WebGL context
 * and a cache of rendered bodies, and rebuilding it on every roster
 * change -- three seconds -- would throw both away.
 *
 * The module is imported dynamically because it pulls in three.js, which
 * is most of the map page's weight and is needed only once artwork has
 * been uploaded.
 */
export function useVehicleRenderer(): VehicleRenderer | null {
  const [renderer, setRenderer] = useState<VehicleRenderer | null>(null)

  // Which vehicles have their artwork uploaded. Rarely changes, so it
  // is fetched once and kept.
  const { data: catalogue } = useQuery({
    queryKey: ['vehicle-catalogue'],
    queryFn: (): Promise<Catalogue> => apiFetch('/vehicle-models/catalogue'),
    staleTime: 10 * 60 * 1000,
    retry: false,
  })

  const drawable = useMemo(() => catalogue?.drawable ?? null, [catalogue])

  useEffect(() => {
    if (drawable === null) {
      return
    }

    let made: VehicleRenderer | null = null
    let abandoned = false

    void import('./vehicle-renderer').then(({ VehicleRenderer: Renderer }) => {
      // The map may have closed while three.js was still loading.
      if (abandoned) {
        return
      }

      made = new Renderer(
        // Served by the panel's own API, so the session cookie applies.
        (name) => `/api/vehicle-models/${encodeURIComponent(name)}`,
        (script) => {
          const bare = script.includes('.') ? script.slice(script.lastIndexOf('.') + 1) : script

          return drawable[bare] ?? null
        },
      )

      setRenderer(made)
    })

    return () => {
      abandoned = true
      made?.dispose()
      setRenderer(null)
    }
  }, [drawable])

  return renderer
}
