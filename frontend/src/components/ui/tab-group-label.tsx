import { cn } from '@/lib/utils'

/**
 * A heading inside a tab strip, so a long strip reads as sections
 * rather than one undifferentiated row.
 *
 * `aria-hidden` because it is a visual grouping only: the tablist's own
 * roles must stay intact, and a label announced between tabs would
 * break the "tab 3 of 5" a screen reader gives.
 */
export function TabGroupLabel({
  children,
  className,
}: {
  children: React.ReactNode
  className?: string
}) {
  return (
    <span
      aria-hidden
      className={cn(
        'select-none px-2 font-mono text-[0.65rem] uppercase tracking-widest text-muted-foreground/60',
        className,
      )}
    >
      {children}
    </span>
  )
}
