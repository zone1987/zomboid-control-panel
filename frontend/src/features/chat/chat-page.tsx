import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Megaphone, MessagesSquare, Send } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import { getServer } from '@/features/servers/servers'
import { MAX_MESSAGE_LENGTH, readChat, sendChat, type ChatLine } from './chat'

/** Enough to scroll back through an evening without straining the browser. */
const MAX_LINES = 500

type Entry = ChatLine & { id: number }

export function ChatPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const [message, setMessage] = useState('')
  const [showSystem, setShowSystem] = useState(false)
  const [entries, setEntries] = useState<Entry[]>([])
  const cursor = useRef<{ file: string | null; offset: number | null }>({ file: null, offset: null })
  const nextId = useRef(0)
  const viewport = useRef<HTMLDivElement>(null)
  const pinned = useRef(true)

  const { data: server } = useQuery({ queryKey: ['server', id], queryFn: () => getServer(id) })

  const { data: chunk } = useQuery({
    queryKey: ['chat', id],
    retry: false,
    refetchInterval: 3_000,
    refetchIntervalInBackground: true,
    queryFn: async () => {
      const result = await readChat(id, cursor.current.file, cursor.current.offset)

      if (result.rotated === true) {
        toast.info(t('chat.rotated'))
      }

      cursor.current = { file: result.file, offset: result.offset }

      if (result.lines.length > 0) {
        setEntries((previous) =>
          [...previous, ...result.lines.map((line) => ({ ...line, id: nextId.current++ }))].slice(
            -MAX_LINES,
          ),
        )
      }

      return result
    },
  })

  useEffect(() => {
    const element = viewport.current

    if (element !== null && pinned.current) {
      element.scrollTop = element.scrollHeight
    }
  }, [entries, showSystem])

  const send = useMutation({
    mutationFn: () => sendChat(id, message),
    onSuccess: () => setMessage(''),
    onError: (error) => {
      toast.error(
        error instanceof ApiError && error.status === 502
          ? t('chat.sendFailed')
          : t('errors.generic'),
      )
    },
  })

  const shown = useMemo(
    () => (showSystem ? entries : entries.filter((entry) => entry.kind !== 'system')),
    [entries, showSystem],
  )

  const tooLong = message.length > MAX_MESSAGE_LENGTH

  return (
    <div className="mx-auto flex h-[calc(100vh-8rem)] max-w-4xl flex-col gap-4">
      <div>
        <h1 className="text-2xl font-semibold">{t('chat.title')}</h1>
        <p className="text-muted-foreground">
          {server ? t('chat.descriptionFor', { server: server.name }) : t('chat.description')}
        </p>
      </div>

      {chunk?.error != null && (
        <Alert>
          <AlertTitle>{t('chat.noLog')}</AlertTitle>
          <AlertDescription>{t('chat.noLogHint')}</AlertDescription>
        </Alert>
      )}

      <div className="flex items-center gap-3">
        <Switch id="show-system" checked={showSystem} onCheckedChange={setShowSystem} />
        <Label htmlFor="show-system" className="font-normal">
          {t('chat.showSystem')}
        </Label>
      </div>

      <div
        ref={viewport}
        className="flex-1 overflow-y-auto rounded-md border bg-muted/30 p-4"
        onScroll={(event) => {
          const element = event.currentTarget
          pinned.current = element.scrollHeight - element.scrollTop - element.clientHeight < 40
        }}
      >
        {shown.length === 0 ? (
          <Empty>
            <EmptyHeader>
              <EmptyMedia variant="icon">
                <MessagesSquare />
              </EmptyMedia>
              <EmptyTitle>{t('chat.empty')}</EmptyTitle>
              <EmptyDescription>{t('chat.emptyHint')}</EmptyDescription>
            </EmptyHeader>
          </Empty>
        ) : (
          <div className="space-y-2">
            {shown.map((entry) => (
              <Line key={entry.id} entry={entry} />
            ))}
          </div>
        )}
      </div>

      <div className="space-y-2">
        <div className="flex gap-2">
          <Input
            value={message}
            placeholder={t('chat.placeholder')}
            maxLength={MAX_MESSAGE_LENGTH + 50}
            onChange={(event) => setMessage(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter' && message.trim() !== '' && !tooLong) {
                send.mutate()
              }
            }}
          />

          <Button
            disabled={message.trim() === '' || tooLong || send.isPending}
            onClick={() => send.mutate()}
          >
            <Send className="size-4" />
            {t('chat.send')}
          </Button>
        </div>

        <p className="flex flex-wrap items-center gap-x-3 text-xs text-muted-foreground">
          <span className="flex items-center gap-1">
            <Megaphone className="size-3.5" />
            {t('chat.broadcastOnly')}
          </span>

          {message.length > MAX_MESSAGE_LENGTH * 0.8 && (
            <span className={tooLong ? 'text-destructive' : undefined}>
              {message.length} / {MAX_MESSAGE_LENGTH}
            </span>
          )}
        </p>
      </div>
    </div>
  )
}

function Line({ entry }: { entry: Entry }) {
  const { t } = useTranslation()

  if (entry.kind === 'system') {
    return (
      <p className="text-xs text-muted-foreground">
        <Stamp value={entry.timestamp} />
        {entry.text}
      </p>
    )
  }

  if (entry.kind === 'broadcast') {
    return (
      <div className="rounded-md border border-primary/30 bg-primary/5 px-3 py-2">
        <p className="mb-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
          <Megaphone className="size-3.5" />
          {t('chat.fromServer')}
          <Stamp value={entry.timestamp} className="ml-auto" />
        </p>
        <p className="text-sm">{entry.text}</p>
      </div>
    )
  }

  return (
    <p className="text-sm">
      <Stamp value={entry.timestamp} />
      {entry.author !== null && <span className="mr-1.5 font-medium">{entry.author}:</span>}
      {entry.text}
    </p>
  )
}

function Stamp({ value, className }: { value: string | null; className?: string }) {
  if (value === null) {
    return null
  }

  // "04-09-26 20:45:31.274" — day, month, two-digit year, then the time.
  const time = value.split(' ')[1]?.slice(0, 8) ?? value

  return (
    <span className={className ?? 'mr-2 font-mono text-xs text-muted-foreground/70'}>{time}</span>
  )
}
