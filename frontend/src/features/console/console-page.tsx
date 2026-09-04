import { useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { AlertTriangle, Send, Terminal, Trash2 } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { getServer } from '@/features/servers/servers'
import { CommandPicker } from './command-picker'
import { checkSyntax, listCommands, runCommand, type ServerCommand } from './console'

type Entry = {
  id: number
  command: string
  reply: string
  failed: boolean
  at: Date
}

export function ConsolePage() {
  const { t, i18n } = useTranslation()
  const { id = '' } = useParams()
  const [line, setLine] = useState('')
  const [entries, setEntries] = useState<Entry[]>([])
  const [confirming, setConfirming] = useState<string | null>(null)
  const [historyIndex, setHistoryIndex] = useState<number | null>(null)
  const transcriptRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  const { data: server } = useQuery({ queryKey: ['server', id], queryFn: () => getServer(id) })

  const { data: catalogue, isPending: commandsPending } = useQuery({
    queryKey: ['console-commands', id],
    queryFn: () => listCommands(id),
    retry: false,
    // The command set only changes when the server is updated, so this is
    // re-read on a long interval rather than on demand.
    refetchInterval: 300_000,
    staleTime: 60_000,
  })

  const commands = useMemo(() => catalogue?.items ?? [], [catalogue])
  const problem = useMemo(() => checkSyntax(line, commands), [line, commands])

  const run = useMutation({
    mutationFn: (command: string) => runCommand(id, command),
    onSuccess: (result) => {
      setEntries((previous) => [
        ...previous,
        { id: Date.now(), command: result.command, reply: result.reply, failed: false, at: new Date() },
      ])
      setLine('')
      setHistoryIndex(null)
    },
    onError: (error, command) => {
      const detail =
        error instanceof ApiError && typeof error.payload === 'object' && error.payload !== null
          ? ((error.payload as { detail?: string }).detail ?? '')
          : ''

      setEntries((previous) => [
        ...previous,
        {
          id: Date.now(),
          command,
          reply: detail === '' ? t('console.failed') : detail,
          failed: true,
          at: new Date(),
        },
      ])
      toast.error(t('console.failed'))
    },
  })

  useEffect(() => {
    transcriptRef.current?.scrollTo({ top: transcriptRef.current.scrollHeight })
  }, [entries])

  const history = useMemo(() => entries.map((entry) => entry.command), [entries])

  const submit = () => {
    const command = line.trim().replace(/^\//, '')

    if (command === '' || problem !== null) {
      return
    }

    const verb = command.split(/\s+/)[0].toLowerCase()

    if (commands.find((entry) => entry.name === verb)?.dangerous === true) {
      setConfirming(command)

      return
    }

    run.mutate(command)
  }

  // Up and down walk back through what was already sent, the way a shell does.
  const recall = (direction: -1 | 1) => {
    if (history.length === 0) {
      return
    }

    const next =
      historyIndex === null
        ? direction === -1
          ? history.length - 1
          : null
        : Math.min(history.length - 1, Math.max(0, historyIndex + direction))

    if (next === null || (historyIndex === history.length - 1 && direction === 1)) {
      setHistoryIndex(null)
      setLine('')

      return
    }

    setHistoryIndex(next)
    setLine(history[next])
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('console.title')}</h1>
        <p className="text-muted-foreground">
          {server ? t('console.descriptionFor', { server: server.name }) : t('console.description')}
        </p>
      </div>

      {commandsPending ? (
        <Skeleton className="h-12 w-full" />
      ) : commands.length === 0 ? (
        <Alert>
          <AlertTitle>{t('console.noCommands')}</AlertTitle>
          <AlertDescription>{t('console.noCommandsHint')}</AlertDescription>
        </Alert>
      ) : (
        <div className="flex flex-wrap items-center gap-3">
          <CommandPicker
            commands={commands}
            onSelect={(command) => {
              setLine(templateFor(command))
              inputRef.current?.focus()
            }}
          />

          <span className="text-xs text-muted-foreground">
            {t('console.commandCount', { count: commands.length })}
          </span>

        </div>
      )}

      <div
        ref={transcriptRef}
        className="h-96 overflow-y-auto rounded-md border bg-muted/30 p-3 font-mono text-sm"
      >
        {entries.length === 0 ? (
          <p className="flex h-full items-center justify-center text-center text-muted-foreground">
            <span className="flex flex-col items-center gap-2">
              <Terminal className="size-6" />
              {t('console.empty')}
            </span>
          </p>
        ) : (
          <div className="space-y-3">
            {entries.map((entry) => (
              <div key={entry.id}>
                <div className="flex items-baseline gap-2">
                  <span className="text-muted-foreground">&gt;</span>
                  <span className="font-medium">{entry.command}</span>
                  <span className="ml-auto text-xs text-muted-foreground">
                    {entry.at.toLocaleTimeString(i18n.language)}
                  </span>
                </div>

                <pre
                  className={
                    entry.failed
                      ? 'mt-1 whitespace-pre-wrap break-words pl-4 text-destructive'
                      : 'mt-1 whitespace-pre-wrap break-words pl-4 text-muted-foreground'
                  }
                >
                  {entry.reply === '' ? t('console.noReply') : entry.reply}
                </pre>
              </div>
            ))}
          </div>
        )}
      </div>

      <div className="space-y-2">
        <div className="flex gap-2">
          <Input
            ref={inputRef}
            value={line}
            className="font-mono"
            placeholder={t('console.placeholder')}
            autoComplete="off"
            spellCheck={false}
            onChange={(event) => setLine(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                submit()
              }

              if (event.key === 'ArrowUp') {
                event.preventDefault()
                recall(-1)
              }

              if (event.key === 'ArrowDown') {
                event.preventDefault()
                recall(1)
              }
            }}
          />

          <Button disabled={line.trim() === '' || problem !== null || run.isPending} onClick={submit}>
            <Send className="size-4" />
            {t('console.send')}
          </Button>

          {entries.length > 0 && (
            <Button variant="ghost" size="icon" title={t('console.clear')} onClick={() => setEntries([])}>
              <Trash2 className="size-4" />
            </Button>
          )}
        </div>

        {problem !== null && <SyntaxHint problem={problem} usage={usageForLine(line, commands)} />}
      </div>

      <AlertDialog open={confirming !== null} onOpenChange={(open) => !open && setConfirming(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t('console.confirmTitle')}</AlertDialogTitle>
            <AlertDialogDescription>
              {t('console.confirmDescription', { command: confirming ?? '' })}
            </AlertDialogDescription>
          </AlertDialogHeader>

          <AlertDialogFooter>
            <AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                if (confirming !== null) {
                  run.mutate(confirming)
                }

                setConfirming(null)
              }}
            >
              {t('console.sendAnyway')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

function SyntaxHint({
  problem,
  usage,
}: {
  problem: NonNullable<ReturnType<typeof checkSyntax>>
  usage: string | null
}) {
  const { t } = useTranslation()

  const message =
    problem.kind === 'unknownCommand'
      ? t('console.problem.unknownCommand', { command: problem.command })
      : problem.kind === 'tooFewArguments'
        ? t('console.problem.tooFewArguments', {
            expected: problem.expected,
            given: problem.given,
            missing: problem.missing,
          })
        : problem.kind === 'tooManyArguments'
          ? t('console.problem.tooManyArguments', {
              expected: problem.expected,
              given: problem.given,
            })
          : t('console.problem.unbalancedQuote')

  return (
    <p className="flex flex-wrap items-start gap-2 text-sm text-amber-600 dark:text-amber-500">
      <AlertTriangle className="mt-0.5 size-4 shrink-0" />
      <span>{message}</span>
      {usage !== null && (
        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs text-foreground">
          {usage}
        </code>
      )}
    </p>
  )
}

/** The syntax of whatever command is currently typed, when the server documented one. */
function usageForLine(line: string, commands: ServerCommand[]): string | null {
  const verb = line.trim().replace(/^\//, '').split(/\s+/)[0]?.toLowerCase() ?? ''

  return commands.find((entry) => entry.name === verb)?.usage ?? null
}

/** Turns "/additem "username" "module.item" count" into a line ready to fill in. */
function templateFor(command: ServerCommand): string {
  if (command.parameters.length === 0) {
    return command.name
  }

  const args = command.parameters.map((parameter) =>
    parameter.quoted ? `"${parameter.name}"` : parameter.name,
  )

  return `${command.name} ${args.join(' ')}`
}
