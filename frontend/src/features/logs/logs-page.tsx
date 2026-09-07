import { useEffect, useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Download, FileText, ScrollText, Search } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { getServer } from '@/features/servers/servers'
import { listLogs, parseLine, tailLog, type LogLine } from './logs'

/** Kept short enough that the browser stays responsive on a busy server. */
const MAX_LINES = 2000

export function LogsPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const [selected, setSelected] = useState<string | null>(null)
  const [needle, setNeedle] = useState('')
  const [lines, setLines] = useState<LogLine[]>([])
  const offset = useRef<number | null>(null)
  const nextId = useRef(0)
  const viewport = useRef<HTMLDivElement>(null)
  const pinned = useRef(true)

  const { data: server } = useQuery({ queryKey: ['server', id], queryFn: () => getServer(id) })

  const { data: files, isPending: filesPending } = useQuery({
    queryKey: ['logs', id],
    queryFn: () => listLogs(id),
    retry: false,
  })

  // Default to the chat log, which is what an operator usually wants.
  useEffect(() => {
    if (selected === null && files !== undefined && files.items.length > 0) {
      const chat = files.items.find((file) => file.kind === 'chat')
      setSelected((chat ?? files.items[0]).path)
    }
  }, [files, selected])

  // Switching file starts a fresh transcript from the end of that file.
  useEffect(() => {
    offset.current = null
    nextId.current = 0
    setLines([])
  }, [selected])

  const { isFetching } = useQuery({
    queryKey: ['log-tail', id, selected],
    enabled: selected !== null,
    refetchInterval: 5000,
    refetchIntervalInBackground: true,
    retry: false,
    queryFn: async () => {
      if (selected === null) {
        return null
      }

      const chunk = await tailLog(id, selected, offset.current)
      offset.current = chunk.offset

      if (chunk.rotated) {
        toast.info(t('logs.rotated'))
      }

      if (chunk.lines.length > 0) {
        setLines((previous) =>
          [...previous, ...chunk.lines.map((line) => parseLine(line, nextId.current++))].slice(
            -MAX_LINES,
          ),
        )
      }

      return chunk
    },
  })

  // Scrolling up should stop the view jumping back down on the next poll.
  useEffect(() => {
    const element = viewport.current

    if (element === null || !pinned.current) {
      return
    }

    element.scrollTop = element.scrollHeight
  }, [lines])

  const shown = useMemo(() => {
    const term = needle.trim().toLowerCase()

    return term === ''
      ? lines
      : lines.filter((line) => line.body.toLowerCase().includes(term))
  }, [lines, needle])

  const activeFile = files?.items.find((file) => file.path === selected)

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('logs.title')}</h1>
        <p className="text-muted-foreground">
          {server ? t('logs.descriptionFor', { server: server.name }) : t('logs.description')}
        </p>
      </div>

      {filesPending ? (
        <Skeleton className="h-12 w-full" />
      ) : files === undefined || files.items.length === 0 ? (
        <Alert>
          <AlertTitle>{t('logs.noFiles')}</AlertTitle>
          <AlertDescription>{t('logs.noFilesHint')}</AlertDescription>
        </Alert>
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-3">
            <Select value={selected ?? undefined} onValueChange={setSelected}>
              <SelectTrigger className="w-64">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {files.items.map((file) => (
                  <SelectItem key={file.path} value={file.path}>
                    {t(`logs.kind.${file.kind}`, { defaultValue: file.kind })}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>

            {/* h-9 matches the input: the wrapper would otherwise stretch
                to the row height and carry the icon up with it. */}
            <div className="relative h-9 flex-1 self-center sm:max-w-xs">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={needle}
                className="pl-8"
                placeholder={t('logs.filter')}
                onChange={(event) => setNeedle(event.target.value)}
              />
            </div>

            {activeFile && (
              <span className="text-xs text-muted-foreground">
                {activeFile.name}
                {isFetching && ` · ${t('logs.reading')}`}
              </span>
            )}
          </div>

          <div
            ref={viewport}
            className="h-[32rem] overflow-auto rounded-md border bg-muted/30 p-3 font-mono text-xs"
            onScroll={(event) => {
              const element = event.currentTarget
              pinned.current =
                element.scrollHeight - element.scrollTop - element.clientHeight < 40
            }}
          >
            {shown.length === 0 ? (
              <Empty>
                <EmptyHeader>
                  <EmptyMedia variant="icon">
                    {lines.length === 0 ? <ScrollText /> : <FileText />}
                  </EmptyMedia>
                  <EmptyTitle>
                    {lines.length === 0 ? t('logs.waiting') : t('logs.noMatch')}
                  </EmptyTitle>
                  <EmptyDescription>
                    {lines.length === 0 ? t('logs.waitingHint') : t('logs.noMatchHint')}
                  </EmptyDescription>
                </EmptyHeader>
              </Empty>
            ) : (
              <div className="space-y-0.5">
                {shown.map((line) => (
                  <div key={line.id} className="flex gap-2">
                    {line.timestamp !== null && (
                      <span className="shrink-0 text-muted-foreground/70">{line.timestamp}</span>
                    )}

                    {line.level !== null && (
                      <span
                        className={
                          line.level === 'error'
                            ? 'shrink-0 text-destructive'
                            : line.level === 'warn'
                              ? 'shrink-0 text-amber-500'
                              : 'shrink-0 text-muted-foreground/70'
                        }
                      >
                        {line.level}
                      </span>
                    )}

                    <span className="whitespace-pre-wrap break-all">{line.body}</span>
                  </div>
                ))}
              </div>
            )}
          </div>

          <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="text-xs text-muted-foreground">
              {needle.trim() === ''
                ? t('logs.lineCount', { count: lines.length })
                : t('logs.matchCount', { shown: shown.length, total: lines.length })}
              {lines.length >= MAX_LINES && ` · ${t('logs.capped', { max: MAX_LINES })}`}
            </span>

            {lines.length > 0 && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => download(shown, activeFile?.name ?? 'log.txt')}
              >
                <Download className="size-4" />
                {t('common.download')}
              </Button>
            )}
          </div>
        </>
      )}
    </div>
  )
}

function download(lines: LogLine[], filename: string): void {
  const body = lines
    .map((line) =>
      [line.timestamp === null ? null : `[${line.timestamp}]`, line.body]
        .filter((part) => part !== null)
        .join(' '),
    )
    .join('\n')

  const url = URL.createObjectURL(new Blob([body], { type: 'text/plain' }))
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  anchor.click()
  URL.revokeObjectURL(url)
}
