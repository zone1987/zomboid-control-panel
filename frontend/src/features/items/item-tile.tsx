import { Minus, Plus } from 'lucide-react'

import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Copyable } from '@/components/ui/copyable'
import { ItemIcon } from './item-icon'
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
        <ItemIcon item={item} className="size-10" />

        {/* `hyphens-auto` breaks German names where a reader expects it
            and shows the hyphen; `break-words` is the backstop for bare
            identifiers, which offer the hyphenator nothing to work with
            and otherwise pushed the tile past the scroller. */}
        <span className="line-clamp-2 hyphens-auto break-words text-xs leading-tight">
          {name}
        </span>
      </button>

      {/* The type is what a console command takes, so it is shown and
          copyable rather than hidden in a tooltip. */}
      <Copyable
        value={item.type}
        className="mt-0.5 min-h-8 justify-center py-2 text-[0.65rem] sm:min-h-0 sm:py-0"
      />

      {/* A fixed three-column grid: justify-between shifts the buttons
          outward as soon as the count grows a digit. */}
      {/* 2rem columns on a phone: a 1.5rem button is under the 32px a
          finger reliably hits. */}
      <div className="mt-1.5 grid grid-cols-[2rem_1fr_2rem] items-center sm:grid-cols-[1.5rem_1fr_1.5rem]">
        <Button
          variant="ghost"
          size="icon"
          className="size-8 justify-self-start sm:size-6"
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
          className="size-8 justify-self-end sm:size-6"
          aria-label={`${name} mehr`}
          onClick={() => onChange(count + 1)}
        >
          <Plus className="size-3" />
        </Button>
      </div>
    </div>
  )
}
