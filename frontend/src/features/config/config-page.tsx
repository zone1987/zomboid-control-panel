import { useMemo, useState } from 'react'
import { useParams, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import type { TFunction } from 'i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ChevronRight, FileWarning, Puzzle, Search } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { countIn, matches, readConfig, writeConfig, type ConfigKind, type ConfigValue } from './config'
import { useConfigDraft, type DraftRow } from './use-config-draft'
import { ValueRow } from './value-row'

const KINDS: ConfigKind[] = ['sandbox', 'ini']

/**
 * A server's own settings files, read over FTP.
 *
 * The file is the truth and the schema only describes it, so the list is
 * what the server actually holds — including options this game build has
 * never heard of, which are shown and marked rather than hidden. An
 * editor that cannot see them would delete them on save.
 */
export function ConfigPage() {
  const { id = '' } = useParams()
  const { t } = useTranslation()
  const [search, setSearch] = useSearchParams()

  // The tab lives in the URL so a link can point at one, and so a
  // reload lands where the operator was.
  const raw = search.get('tab')
  const kind: ConfigKind = raw === 'ini' ? 'ini' : 'sandbox'

  return (
    <div className="min-h-full space-y-6 p-4 pb-4 sm:p-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">{t('config.title')}</h1>
        <p className="text-muted-foreground text-sm">{t('config.subtitle')}</p>
      </div>

      <Tabs
        value={kind}
        onValueChange={(next) => setSearch({ tab: next }, { replace: true })}
      >
        <TabsList>
          {KINDS.map((each) => (
            <TabsTrigger key={each} value={each}>
              {t(each === 'sandbox' ? 'config.tabSandbox' : 'config.tabIni')}
            </TabsTrigger>
          ))}
        </TabsList>

        {KINDS.map((each) => (
          <TabsContent key={each} value={each}>
            <ConfigFileView serverId={id} kind={each} active={each === kind} />
          </TabsContent>
        ))}
      </Tabs>
    </div>
  )
}

function ConfigFileView({
  serverId,
  kind,
  active,
}: {
  serverId: string
  kind: ConfigKind
  active: boolean
}) {
  const { t, i18n } = useTranslation()
  const [term, setTerm] = useState('')

  const queryClient = useQueryClient()

  const { data, error, isPending } = useQuery({
    queryKey: ['server-config', serverId, kind],
    queryFn: () => readConfig(serverId, kind),
    // Only the tab in view: reading the other file means a second FTP
    // round trip for something nobody is looking at.
    enabled: serverId !== '' && active,
    retry: false,
  })

  const draft = useConfigDraft(data?.values ?? [])

  const save = useMutation({
    mutationFn: () => writeConfig(serverId, kind, draft.pending),
    onSuccess: async (result) => {
      // Clearing before the refetch would drop the display to the stale
      // value for a render; clearing only the saved keys leaves anything
      // typed since the request went out.
      await queryClient.invalidateQueries({ queryKey: ['server-config', serverId, kind] })
      draft.clearKeys(result.written)

      const description = t(`config.apply.${result.apply}`)

      if (result.backup.state === 'failed') {
        toast.warning(t('config.backupFailed'), { description })

        return
      }

      if (result.restartNeeded) {
        toast.warning(t('config.savedTitle'), { description })

        return
      }

      toast.success(t('config.savedTitle'), { description })
    },
    onError: (failure) => {
      toast.error(t('config.saveFailed'), { description: reasonFor(failure, t) })
    },
  })

  const filtered = useMemo(
    () => (data?.values ?? []).filter((value) => matches(value, term, i18n.language)),
    [data?.values, term, i18n.language],
  )

  if (isPending) {
    return (
      <div className="space-y-3 pt-4">
        <Skeleton className="h-9 w-full max-w-sm" />
        <Skeleton className="h-40 w-full" />
      </div>
    )
  }

  if (error !== null || data === undefined) {
    return <ReadFailure kind={kind} error={error} />
  }

  // Grouped as the game groups them, plus one band for whatever the file
  // holds that no group claims -- a mod's values land there rather than
  // vanishing.
  const grouped = data.groups
    .map((group) => ({
      name: group.name,
      values: filtered.filter((value) => group.options.includes(value.key)),
      total: countIn(group, data.values),
    }))
    .filter((group) => group.total > 0)

  const claimed = new Set(data.groups.flatMap((group) => group.options))
  const unclaimed = filtered.filter((value) => !claimed.has(value.key))

  return (
    <div className="space-y-4 pt-4">
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative max-w-sm flex-1">
          <Search
            className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
            aria-hidden
          />
          <Input
            value={term}
            onChange={(event) => setTerm(event.target.value)}
            placeholder={t('config.searchPlaceholder')}
            aria-label={t('config.searchPlaceholder')}
            className="pl-9"
          />
        </div>

        <p className="text-muted-foreground text-sm">
          {term === ''
            ? t('config.valueCount', { count: data.values.length })
            : t('config.matchCount', { count: filtered.length, total: data.values.length })}
        </p>

        {data.unknown > 0 && (
          <Badge variant="secondary" className="gap-1">
            <Puzzle className="size-3" aria-hidden />
            {t('config.unknownCount', { count: data.unknown })}
          </Badge>
        )}
      </div>

      <p className="text-muted-foreground font-mono text-xs">
        {data.path}
        {data.buildId !== null && <span className="ml-2 font-sans">· {data.buildId}</span>}
      </p>

      {/* Several matches is the operator's choice, not a fault. */}
      {data.candidates.length > 1 && (
        <p className="text-sm text-amber-600 dark:text-amber-400">
          {t('config.severalFiles', { names: data.candidates.join(', ') })}
        </p>
      )}

      {/* Keyed off `touched`, not `count`: a row holding only an
          unusable value has nothing to send, and a bar that disappears
          leaves no way to discard it. */}
      {draft.touched > 0 && (
        <div className="bg-primary/5 border-primary/20 sticky bottom-0 z-10 flex flex-wrap items-center gap-3 rounded-md border px-4 py-3 backdrop-blur">
          <span className="font-medium">{t('config.pendingCount', { count: draft.touched })}</span>

          {draft.blocked && (
            <span className="text-destructive text-sm">{t('config.someInvalid')}</span>
          )}

          <div className="ml-auto flex items-center gap-2">
            <Button variant="ghost" onClick={draft.clear} disabled={save.isPending}>
              {t('config.discard')}
            </Button>
            <Button onClick={() => save.mutate()} disabled={save.isPending || draft.blocked}>
              {save.isPending ? t('config.saving') : t('config.save')}
            </Button>
          </div>
        </div>
      )}

      {grouped.length === 0 && unclaimed.length === 0 ? (
        <Card>
          <CardContent className="text-muted-foreground py-8 text-center text-sm">
            {t('config.noMatches', { term })}
          </CardContent>
        </Card>
      ) : (
        <div className="space-y-3">
          {grouped.map((group) => (
            <Group
              key={group.name}
              name={t(`config.groups.${group.name}`, { defaultValue: group.name })}
              values={group.values}
              total={group.total}
              rows={draft.rows}
              onChange={draft.set}
              onReset={(key) => draft.clearKeys([key])}
              // A search that matched something opens the sections
              // holding it: hunting through ten closed bands for a
              // result the count already promised is busywork.
              open={term !== '' && group.values.length > 0}
            />
          ))}

          {unclaimed.length > 0 && (
            <Group
              name={t('config.ungrouped')}
              values={unclaimed}
              total={unclaimed.length}
              open={term !== ''}
              rows={draft.rows}
              onChange={draft.set}
              onReset={(key) => draft.clearKeys([key])}
            />
          )}
        </div>
      )}
    </div>
  )
}

function Group({
  name,
  values,
  total,
  open,
  rows,
  onChange,
  onReset,
}: {
  name: string
  values: ConfigValue[]
  total: number
  open: boolean
  rows: Map<string, DraftRow>
  onChange: (key: string, text: string) => void
  onReset: (key: string) => void
}) {
  const { t } = useTranslation()

  // The open state is held against the `open` it was decided under, the
  // house pattern for "server value unless somebody touched it": a
  // search opening a group must not then keep it shut when the operator
  // clicks it, and clearing the search must not slam it.
  const [toggled, setToggled] = useState<{ from: boolean; open: boolean } | null>(null)
  const shown = toggled !== null && toggled.from === open ? toggled.open : open

  if (values.length === 0) {
    return null
  }

  return (
    // Controlled rather than defaultOpen: that only applies on the
    // first render, so typing a search term left the matching group
    // shut with its count promising a result inside.
    <Collapsible
      open={shown}
      onOpenChange={(next) => setToggled({ from: open, open: next })}
      className="group/config"
    >
      <Card className="overflow-hidden py-0">
        <CollapsibleTrigger className="hover:bg-muted/50 flex w-full cursor-pointer items-center gap-3 px-4 py-3 text-left transition-colors">
          <ChevronRight className="size-4 shrink-0 transition-transform group-data-[state=open]/config:rotate-90" />
          <span className="font-medium">{name}</span>
          <span className="text-muted-foreground ml-auto text-sm">
            {values.length === total
              ? t('config.groupCount', { count: total })
              : t('config.groupMatchCount', { count: values.length, total })}
          </span>
        </CollapsibleTrigger>

        <CollapsibleContent>
          <div className="border-t">
            {values.map((value) => (
              <ValueRow
                key={value.key}
                value={value}
                row={rows.get(value.key) ?? unchanged(value)}
                onChange={(text) => onChange(value.key, text)}
                onReset={() => onReset(value.key)}
              />
            ))}
          </div>
        </CollapsibleContent>
      </Card>
    </Collapsible>
  )
}

/** Says which failure it was, and what the operator can do about it. */
function ReadFailure({ kind, error }: { kind: ConfigKind; error: unknown }) {
  const { t } = useTranslation()

  const reason =
    error instanceof ApiError && error.status === 404
      ? t(kind === 'sandbox' ? 'config.noSandboxFile' : 'config.noIniFile')
      : error instanceof ApiError && error.status === 409
        ? t('config.noTransfer')
        : t('config.readFailed')

  return (
    <Card className="mt-4">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <FileWarning className="size-4" aria-hidden />
          {t('config.cannotRead')}
        </CardTitle>
        <CardDescription>{reason}</CardDescription>
      </CardHeader>
    </Card>
  )
}

/** A row for a value nobody has touched. */
function unchanged(value: ConfigValue): DraftRow {
  return {
    shown: value.value,
    parsed: value.value,
    invalid: false,
    outOfBounds: false,
    changed: false,
  }
}

/**
 * Why a save failed, in the operator's terms.
 *
 * The read-back mismatch is the one that matters most: it means the file
 * on the server does not hold what was sent, and whether the backup came
 * back is a different fact again.
 */
function reasonFor(failure: unknown, t: TFunction): string {
  if (!(failure instanceof ApiError)) {
    return t('config.readFailed')
  }

  const body = failure.payload as
    | { error?: string; keys?: string[]; mismatched?: string[]; restored?: boolean }
    | undefined

  if (body?.error === 'config.readBackMismatch') {
    return t(
      body.restored === true
        ? 'config.readBackMismatch'
        : 'config.readBackMismatchNotRestored',
    )
  }

  if (body?.keys !== undefined && body.keys.length > 0) {
    return t('config.writeRefused', { keys: body.keys.join(', ') })
  }

  return body?.error === undefined ? t('config.readFailed') : t(body.error)
}
