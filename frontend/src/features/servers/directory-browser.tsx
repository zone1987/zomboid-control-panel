import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ChevronRight, File, Folder, Home } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { browse } from './servers'

export function DirectoryBrowser({
  serverId,
  open,
  onClose,
  onSelect,
  title,
}: {
  serverId: string
  open: boolean
  onClose: () => void
  onSelect: (path: string) => void
  title: string
}) {
  const { t } = useTranslation()
  const [path, setPath] = useState('')

  const { data, error, isPending } = useQuery({
    queryKey: ['server-files', serverId, path],
    queryFn: () => browse(serverId, path),
    enabled: open,
    retry: false,
  })

  const segments = path === '' ? [] : path.split('/')

  return (
    <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{t('servers.browseHint')}</DialogDescription>
        </DialogHeader>

        <nav className="flex flex-wrap items-center gap-1 text-sm">
          <Button variant="ghost" size="sm" className="h-7 gap-1 px-2" onClick={() => setPath('')}>
            <Home className="size-3.5" />
            {t('servers.rootDirectory')}
          </Button>

          {segments.map((segment, index) => (
            <span key={segment + index} className="flex items-center gap-1">
              <ChevronRight className="size-3.5 text-muted-foreground" />
              <Button
                variant="ghost"
                size="sm"
                className="h-7 px-2"
                onClick={() => setPath(segments.slice(0, index + 1).join('/'))}
              >
                {segment}
              </Button>
            </span>
          ))}
        </nav>

        <div className="h-80 overflow-y-auto rounded-md border">
          {isPending && <Skeleton className="m-3 h-64" />}

          {error && (
            <Alert variant="destructive" className="m-3 w-auto">
              <AlertTitle>{t('servers.browseFailed')}</AlertTitle>
              <AlertDescription>
                {error instanceof ApiError ? readErrorKey(error, t) : t('errors.generic')}
              </AlertDescription>
            </Alert>
          )}

          {data && data.entries.length === 0 && (
            <p className="p-4 text-sm text-muted-foreground">{t('servers.emptyDirectory')}</p>
          )}

          {data && data.entries.length > 0 && (
            <ul className="divide-y">
              {data.entries.map((entry) => (
                <li key={entry.path}>
                  <button
                    type="button"
                    className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-accent disabled:opacity-50"
                    disabled={entry.type === 'file'}
                    onClick={() => setPath(entry.path)}
                  >
                    {entry.type === 'directory' ? (
                      <Folder className="size-4 shrink-0 text-muted-foreground" />
                    ) : (
                      <File className="size-4 shrink-0 text-muted-foreground" />
                    )}
                    <span className="truncate">{entry.name}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button
            onClick={() => {
              onSelect(path === '' ? '.' : path)
              onClose()
            }}
          >
            {t('servers.useThisDirectory')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function readErrorKey(error: ApiError, t: (key: string) => string): string {
  const payload = error.payload

  if (typeof payload === 'object' && payload !== null && 'error' in payload) {
    const key = (payload as { error: unknown }).error

    if (typeof key === 'string') {
      return t(key)
    }
  }

  return t('errors.generic')
}
