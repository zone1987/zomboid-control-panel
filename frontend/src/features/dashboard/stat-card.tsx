import type { LucideIcon } from 'lucide-react'

import { cn } from '@/lib/utils'

/** A headline number with its unit and a caption, as the panel shows counts. */
export function StatCard({
  icon: Icon,
  value,
  unit,
  caption,
  accent = false,
}: {
  icon: LucideIcon
  value: string | number
  unit?: string
  caption: string
  accent?: boolean
}) {
  return (
    <div
      className={cn(
        'pz-interactive flex items-center gap-3 rounded-md border p-4',
        accent && 'border-l-2 border-l-primary',
      )}
    >
      <div className="flex size-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
        <Icon className="size-4" />
      </div>

      <div className="min-w-0">
        <p className="flex items-baseline gap-1.5">
          <span className="font-mono text-2xl font-semibold leading-none">{value}</span>
          {unit !== undefined && (
            <span className="text-xs text-muted-foreground">{unit}</span>
          )}
        </p>
        <p className="mt-1 truncate text-xs uppercase tracking-wide text-muted-foreground">
          {caption}
        </p>
      </div>
    </div>
  )
}
