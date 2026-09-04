import { Minus, Package, Plus } from 'lucide-react'

import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { displayName, type Item } from './items'

/** The same item as a row, for when the grid is too coarse to scan. */
export function ItemRow({
  item,
  count,
  onChange,
}: {
  item: Item
  count: number
  onChange: (count: number) => void
}) {
  const name = displayName(item)

  return (
    <div
      className={cn(
        'flex items-center gap-3 rounded-md border px-3 py-2',
        count > 0 ? 'border-primary bg-primary/5' : 'hover:bg-accent/50',
      )}
    >
      <span className="flex size-8 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground">
        <Package className="size-4" />
      </span>

      <div className="min-w-0 flex-1">
        <p className="truncate text-sm">{name}</p>
        <p className="truncate font-mono text-xs text-muted-foreground">{item.type}</p>
      </div>

      {item.category !== undefined && (
        <span className="hidden shrink-0 text-xs text-muted-foreground sm:inline">
          {item.category}
        </span>
      )}

      <div className="grid shrink-0 grid-cols-[1.75rem_2.5rem_1.75rem] items-center">
        <Button
          variant="ghost"
          size="icon"
          className="size-7 justify-self-center"
          disabled={count === 0}
          aria-label={`${name} weniger`}
          onClick={() => onChange(count - 1)}
        >
          <Minus className="size-3.5" />
        </Button>

        <span className="text-center text-sm tabular-nums">{count}</span>

        <Button
          variant="ghost"
          size="icon"
          className="size-7 justify-self-center"
          aria-label={`${name} mehr`}
          onClick={() => onChange(count + 1)}
        >
          <Plus className="size-3.5" />
        </Button>
      </div>
    </div>
  )
}
