import { cn } from '@/lib/utils'

/**
 * The panel's mark.
 *
 * A hexagon holding three stacked layers with a control point on the
 * middle one: the panel's own subject, drawn in the same line language
 * as the navigation icons rather than in the game's artwork.
 *
 * Every colour is `currentColor` at some opacity, so the mark takes the
 * colour of whatever contains it and needs no variant per theme.
 */
export function BrandMark({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 64 64"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden="true"
      className={cn('shrink-0', className)}
    >
      <path
        d="M32 2.4 56.8 16.8v30.4L32 61.6 7.2 47.2V16.8z"
        fill="currentColor"
        fillOpacity="0.1"
        stroke="currentColor"
        strokeOpacity="0.45"
        strokeWidth="2.4"
        strokeLinejoin="round"
      />

      <rect
        x="16.4"
        y="16.2"
        width="31.2"
        height="10"
        rx="3.4"
        stroke="currentColor"
        strokeOpacity="0.75"
        strokeWidth="2.2"
      />
      <rect
        x="16.4"
        y="27"
        width="31.2"
        height="10"
        rx="3.4"
        stroke="currentColor"
        strokeWidth="2.4"
      />
      <rect
        x="16.4"
        y="37.8"
        width="31.2"
        height="10"
        rx="3.4"
        stroke="currentColor"
        strokeOpacity="0.75"
        strokeWidth="2.2"
      />

      <circle cx="23.2" cy="32" r="2.7" fill="currentColor" />
    </svg>
  )
}
