import { Minus, Package, Plus } from 'lucide-react'

import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { displayName, type Item } from './items'

/**
 * One item in the grid. The icon is a placeholder until the panel can
 * reach the game's own artwork; the name carries the meaning meanwhile.
 */
export function ItemTile({
  item,
  count,
  onChange,
}: {
  item: Item
  count: number
  onChange: (count: number) => void
}) {
  const name = displayName(item)
  const selected = count > 0

  return (
    <div
      className={cn(
        'flex flex-col rounded-md border p-2 transition-colors',
        selected ? 'border-primary bg-primary/5' : 'hover:bg-accent/50',
      )}
    >
      <button
        type="button"
        className="flex flex-1 flex-col items-center gap-1.5 text-center"
        title={item.type}
        onClick={() => onChange(count + 1)}
      >
        <span className="flex size-10 items-center justify-center rounded bg-muted text-muted-foreground">
          <Package className="size-5" />
        </span>

        <span className="line-clamp-2 text-xs leading-tight">{name}</span>
      </button>

      {/* A fixed three-column grid: justify-between shifts the buttons
          outward as soon as the count grows a digit. */}
      <div className="mt-1.5 grid grid-cols-[1.5rem_1fr_1.5rem] items-center">
        <Button
          variant="ghost"
          size="icon"
          className="size-6 justify-self-start"
          disabled={count === 0}
          aria-label={`${name} weniger`}
          onClick={() => onChange(count - 1)}
        >
          <Minus className="size-3" />
        </Button>

        <span
          className={cn(
            'text-center text-xs tabular-nums',
            selected ? 'font-medium' : 'text-muted-foreground',
          )}
        >
          {count}
        </span>

        <Button
          variant="ghost"
          size="icon"
          className="size-6 justify-self-end"
          aria-label={`${name} mehr`}
          onClick={() => onChange(count + 1)}
        >
          <Plus className="size-3" />
        </Button>
      </div>
    </div>
  )
}
