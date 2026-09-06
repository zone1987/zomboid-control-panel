import { cn } from '@/lib/utils'

/** Intrinsic sizes, so the layout does not shift while an image loads. */
const SIZES = {
  wordmark: { width: 547, height: 560 },
  badge: { width: 500, height: 560 },
  mark: { width: 512, height: 512 },
} as const

/**
 * The panel's logo, in the variant that fits the space.
 *
 * `wordmark` carries the name and belongs above a sign-in form;
 * `badge` is the same artwork without the type; `mark` is the bare
 * hexagon for anywhere a single small icon is wanted.
 *
 * AVIF and WebP are both offered and the browser takes the first it
 * understands. The mark keeps a PNG fallback because it is transparent
 * and used in frames that would otherwise show a hole.
 */
export function BrandLogo({
  variant = 'wordmark',
  className,
  priority = false,
}: {
  variant?: 'wordmark' | 'badge' | 'mark'
  className?: string
  priority?: boolean
}) {
  const alt = 'ZomboidControl'

  if (variant === 'mark') {
    return (
      <picture>
        <source srcSet="/app/brand/mark-512.webp" type="image/webp" />
        <img
          src="/app/brand/mark-192.png"
          alt={alt}
          width={192}
          height={192}
          loading={priority ? 'eager' : 'lazy'}
          decoding="async"
          className={cn('select-none', className)}
        />
      </picture>
    )
  }

  return (
    <picture>
      <source srcSet={`/app/brand/${variant}.avif`} type="image/avif" />
      <source srcSet={`/app/brand/${variant}.webp`} type="image/webp" />
      <img
        src={`/app/brand/${variant}.webp`}
        alt={alt}
        width={SIZES[variant].width}
        height={SIZES[variant].height}
        loading={priority ? 'eager' : 'lazy'}
        decoding="async"
        className={cn('select-none', className)}
      />
    </picture>
  )
}
