import { useMemo, useState } from 'react'
import { useParams, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { ChevronRight, FileWarning, Puzzle, Search } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { countIn, matches, readConfig, type ConfigKind, type ConfigValue } from './config'
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

  const { data, error, isPending } = useQuery({
    queryKey: ['server-config', serverId, kind],
    queryFn: () => readConfig(serverId, kind),
    // Only the tab in view: reading the other file means a second FTP
    // round trip for something nobody is looking at.
    enabled: serverId !== '' && active,
    retry: false,
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
}: {
  name: string
  values: ConfigValue[]
  total: number
  open: boolean
}) {
  const { t } = useTranslation()

  if (values.length === 0) {
    return null
  }

  return (
    <Collapsible defaultOpen={open} className="group/config">
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
              <ValueRow key={value.key} value={value} />
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
