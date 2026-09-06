import { useEffect, useState } from 'react'
import { Car } from 'lucide-react'

import { cn } from '@/lib/utils'
import type { VehicleRenderer } from '@/features/map/vehicle-renderer'

/**
 * A vehicle drawn from the game's own model, or a fallback icon.
 *
 * The same renderer the map uses, so there is one vehicle appearance in
 * the panel. One fixed heading per tile rather than the map's twenty-four
 * buckets: a grid of fifty tiles is fifty renders, not twelve hundred.
 *
 * The icon is shown whenever the artwork is missing, unuploaded or fails
 * to render. A vehicle the panel cannot picture is still spawnable, so
 * hiding it would remove a working capability.
 */
export function VehiclePreview({
  script,
  renderer,
  className,
}: {
  script: string
  renderer: VehicleRenderer | null
  className?: string
}) {
  const [url, setUrl] = useState<string | null>(null)

  useEffect(() => {
    if (renderer === null) {
      return
    }

    let abandoned = false

    void renderer
      .draw({
        id: 0,
        script,
        x: 0,
        y: 0,
        z: 0,
        // Straight on, so every tile shows the same face.
        heading: 0,
        skin: null,
        engineRunning: false,
      })
      .then((rendered) => {
        if (!abandoned) {
          setUrl(rendered?.url ?? null)
        }
      })

    return () => {
      abandoned = true
    }
  }, [script, renderer])

  if (url === null) {
    return (
      <span
        className={cn('flex items-center justify-center text-muted-foreground/50', className)}
      >
        <Car className="size-2/3" />
      </span>
    )
  }

  return (
    <img
      src={url}
      alt=""
      loading="lazy"
      decoding="async"
      className={cn('object-contain', className)}
    />
  )
}
