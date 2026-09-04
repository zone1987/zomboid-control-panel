import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronsUpDown, TriangleAlert } from 'lucide-react'

import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import type { ServerCommand } from './console'

export function CommandPicker({
  commands,
  onSelect,
}: {
  commands: ServerCommand[]
  onSelect: (command: ServerCommand) => void
}) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const [needle, setNeedle] = useState('')

  const term = needle.trim().toLowerCase()
  const matches =
    term === ''
      ? commands
      : commands.filter(
          (command) =>
            command.name.includes(term) || command.description.toLowerCase().includes(term),
        )

  return (
    <Popover
      open={open}
      onOpenChange={(next) => {
        setOpen(next)

        if (!next) {
          setNeedle('')
        }
      }}
    >
      <PopoverTrigger asChild>
        <Button variant="outline" size="sm" className="justify-between gap-2">
          {t('console.pickCommand')}
          <ChevronsUpDown className="size-4 opacity-50" />
        </Button>
      </PopoverTrigger>

      <PopoverContent align="start" className="w-[min(32rem,calc(100vw-2rem))] p-0">
        <div className="border-b p-2">
          <Input
            autoFocus
            value={needle}
            placeholder={t('common.search')}
            onChange={(event) => setNeedle(event.target.value)}
          />
        </div>

        <div className="max-h-80 overflow-y-auto p-1">
          {matches.length === 0 ? (
            <p className="p-3 text-sm text-muted-foreground">{t('console.noMatch')}</p>
          ) : (
            matches.map((command) => (
              <button
                key={command.name}
                type="button"
                className={cn(
                  'flex w-full flex-col gap-0.5 rounded-sm px-2 py-2 text-left',
                  'hover:bg-accent hover:text-accent-foreground',
                )}
                onClick={() => {
                  onSelect(command)
                  setOpen(false)
                  setNeedle('')
                }}
              >
                <span className="flex items-center gap-2">
                  <code className="font-mono text-sm font-medium">{command.name}</code>

                  {command.dangerous && (
                    <Badge variant="destructive" className="gap-1 text-xs">
                      <TriangleAlert className="size-3" />
                      {t('console.dangerous')}
                    </Badge>
                  )}
                </span>

                {command.description !== '' && (
                  <span className="line-clamp-2 text-xs text-muted-foreground">
                    {command.description}
                  </span>
                )}

                {command.usage !== null && (
                  <code className="font-mono text-xs text-muted-foreground">{command.usage}</code>
                )}
              </button>
            ))
          )}
        </div>
      </PopoverContent>
    </Popover>
  )
}
