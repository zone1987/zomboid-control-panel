import { Link } from 'react-router'
import { CircleAlert, CircleCheck, type LucideIcon } from 'lucide-react'

import { cn } from '@/lib/utils'

/**
 * A link to one area of the panel that also reports that area's state, so
 * the overview answers "where do I go" and "is anything wrong" at once.
 */
export function SectionTile({
  icon: Icon,
  label,
  state,
  tone = 'neutral',
  to,
}: {
  icon: LucideIcon
  label: string
  state: string
  tone?: 'neutral' | 'good' | 'warn'
  to: string
}) {
  return (
    <Link
      to={to}
      className="pz-interactive flex items-center gap-3 rounded-md border p-3 hover:bg-accent/50"
    >
      <Icon className="size-4 shrink-0 text-muted-foreground" />

      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium">{label}</p>
        <p
          className={cn(
            'flex items-center gap-1 truncate font-mono text-xs',
            tone === 'good' && 'text-primary',
            tone === 'warn' && 'text-amber-600 dark:text-amber-500',
            tone === 'neutral' && 'text-muted-foreground',
          )}
        >
          {/* A shape as well as a hue: red against green is the most
              common form of colour blindness. */}
          {tone === 'warn' && <CircleAlert className="size-3 shrink-0" aria-hidden />}
          {tone === 'good' && <CircleCheck className="size-3 shrink-0" aria-hidden />}
          <span className="truncate">{state}</span>
        </p>
      </div>
    </Link>
  )
}
