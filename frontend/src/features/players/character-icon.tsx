import { useState } from 'react'

import { cn } from '@/lib/utils'
import { iconUrl } from './character'

/**
 * The game's own artwork for a profession or trait.
 *
 * Falls back to a letter rather than a broken image: two of the
 * ninety-seven traits have no icon in the installation at all ("out of
 * shape", "very underweight"), and an operator who has not extracted
 * the artwork has none of them.
 */
export function CharacterIcon({
  icon,
  label,
  className,
}: {
  icon: string | null
  label: string
  className?: string
}) {
  const [failed, setFailed] = useState(false)

  if (icon === null || failed) {
    return (
      <span
        aria-hidden
        className={cn(
          'flex size-6 shrink-0 items-center justify-center rounded bg-muted text-[10px] font-medium text-muted-foreground',
          className,
        )}
      >
        {label.slice(0, 1).toUpperCase()}
      </span>
    )
  }

  return (
    <img
      src={iconUrl(icon)}
      alt=""
      aria-hidden
      loading="lazy"
      className={cn('size-6 shrink-0 object-contain', className)}
      onError={() => setFailed(true)}
    />
  )
}
