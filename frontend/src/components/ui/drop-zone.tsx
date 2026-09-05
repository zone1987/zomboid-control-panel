import { useRef, useState, type ReactNode } from 'react'
import { Upload } from 'lucide-react'

import { cn } from '@/lib/utils'

/**
 * A file picker that also accepts a drop.
 *
 * Dragging a folder full of packs out of Finder is how people
 * actually do this, and a button alone makes them walk a file dialog
 * to a path they had to be told about.
 */
export function DropZone({
  accept,
  multiple = true,
  disabled = false,
  onFiles,
  children,
}: {
  /** Extensions, as an input's accept attribute takes them. */
  accept?: string
  multiple?: boolean
  disabled?: boolean
  onFiles: (files: File[]) => void
  children: ReactNode
}) {
  const picker = useRef<HTMLInputElement>(null)
  const [over, setOver] = useState(false)
  // dragenter and dragleave fire for every child element the pointer
  // crosses, so a plain boolean flickers; counting them does not.
  const depth = useRef(0)

  const suffixes = (accept ?? '')
    .split(',')
    .map((entry) => entry.trim().toLowerCase())
    .filter((entry) => entry.startsWith('.'))

  const keep = (files: FileList | null): File[] => {
    const all = Array.from(files ?? [])

    if (suffixes.length === 0) {
      return all
    }

    return all.filter((file) => suffixes.some((suffix) => file.name.toLowerCase().endsWith(suffix)))
  }

  const hand = (files: FileList | null) => {
    const wanted = keep(files)

    if (wanted.length > 0) {
      onFiles(wanted)
    }
  }

  return (
    <div
      role="button"
      tabIndex={disabled ? -1 : 0}
      aria-disabled={disabled}
      onClick={() => !disabled && picker.current?.click()}
      onKeyDown={(event) => {
        if (!disabled && (event.key === 'Enter' || event.key === ' ')) {
          event.preventDefault()
          picker.current?.click()
        }
      }}
      onDragEnter={(event) => {
        event.preventDefault()
        depth.current += 1
        if (!disabled) setOver(true)
      }}
      onDragOver={(event) => event.preventDefault()}
      onDragLeave={(event) => {
        event.preventDefault()
        depth.current -= 1
        if (depth.current <= 0) setOver(false)
      }}
      onDrop={(event) => {
        event.preventDefault()
        depth.current = 0
        setOver(false)

        if (!disabled) {
          hand(event.dataTransfer.files)
        }
      }}
      className={cn(
        'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border border-dashed px-4 py-8 text-center transition-colors',
        'hover:border-primary/50 hover:bg-muted/40',
        'focus-visible:ring-ring/50 focus-visible:outline-none focus-visible:ring-[3px]',
        over && 'border-primary bg-primary/5',
        disabled && 'pointer-events-none opacity-60',
      )}
    >
      <Upload className={cn('size-6 shrink-0 text-muted-foreground', over && 'text-primary')} />

      {children}

      <input
        ref={picker}
        type="file"
        accept={accept}
        multiple={multiple}
        hidden
        onChange={(event) => {
          hand(event.target.files)
          // Picking the same file twice in a row must still fire.
          event.target.value = ''
        }}
      />
    </div>
  )
}
