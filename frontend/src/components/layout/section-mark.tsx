import { cn } from '@/lib/utils'

/**
 * A monospace section label: `// list · live`.
 *
 * The parts are separate elements so each can carry its own weight; a
 * single string cannot fade the slashes behind the words.
 */
export function SectionMark({
  label,
  state,
  className,
}: {
  label: string
  state?: string
  className?: string
}) {
  return (
    <div
      className={cn(
        'flex items-center gap-1.5 font-mono text-xs uppercase tracking-wider',
        className,
      )}
    >
      <span className="text-muted-foreground/40">//</span>
      <span className="text-muted-foreground">{label}</span>
      {state !== undefined && (
        <>
          <span className="text-muted-foreground/40">·</span>
          <span className="text-primary">{state}</span>
        </>
      )}
    </div>
  )
}
