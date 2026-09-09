import { useState } from 'react'
import { useNavigate, useParams } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ChevronDown, KeyRound, Search, X } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { cn } from '@/lib/utils'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible'
import { Empty } from '@/components/ui/empty'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { ModTile } from './mod-tile'
import { DiagnosisCard } from './diagnosis-card'
import {
  addMod,
  applyLoadOrder,
  diagnoseMods,
  categoriesOf,
  listInstalled,
  matchesSearch,
  readWorkshopId,
  removeMod,
  searchMods,
  type SortOrder,
} from './mods'

const SORTS: SortOrder[] = ['trend', 'subscriptions', 'updated', 'recent']

export function ModsPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  // Discover first: an operator opening this page usually wants to
  // find something, and an empty installed list is a dead end.
  const [tab, setTab] = useState('discover')
  const [needle, setNeedle] = useState('')
  const [term, setTerm] = useState('')
  const [sort, setSort] = useState<SortOrder>('trend')
  const [tags, setTags] = useState<string[]>([])
  // Held against the mod it belongs to, so a slow answer cannot leave
  // the spinner on a different card (rule 10g3).
  const [pending, setPending] = useState<string | null>(null)

  const installed = useQuery({
    queryKey: ['mods', id, 'installed'],
    queryFn: () => listInstalled(id),
  })

  const results = useQuery({
    queryKey: ['mods', id, 'search', term, sort, tags],
    queryFn: () => searchMods(id, { term, sort, tags }),
    enabled: tab === 'discover',
  })

  // Its own query: it costs several workshop lookups and an FTP walk
  // per mod, so it must not slow the list down.
  const diagnosis = useQuery({
    queryKey: ['mods', id, 'diagnosis'],
    queryFn: () => diagnoseMods(id),
    enabled: tab === 'installed',
  })

  const order = useMutation({
    mutationFn: () => applyLoadOrder(id),
    onSuccess: async (result) => {
      if (result.status === 'alreadyOrdered') {
        toast.success(t('mods.alreadyOrdered'))
      } else if (result.status === 'cycle') {
        toast.error(t('mods.cycleTitle'))
      } else if (result.status === 'notVerified') {
        toast.error(t('mods.notVerified'))
      } else {
        toast.success(t('mods.orderApplied'))
      }

      await queryClient.invalidateQueries({ queryKey: ['mods', id] })
    },
    onError: () => toast.error(t('errors.generic')),
  })

  const change = useMutation({
    mutationFn: ({ workshopId, add }: { workshopId: string; add: boolean }) =>
      add ? addMod(id, workshopId) : removeMod(id, workshopId),
    onSuccess: async (result, variables) => {
      if (result.status === 'keysMissing') {
        toast.error(t('mods.keysMissing', { keys: result.missingKeys.join(', ') }))
      } else if (result.status === 'notVerified') {
        // Not "saved": the file may hold something other than what was
        // asked for, and saying it worked would be the worse mistake.
        toast.error(t('mods.notVerified'))
      } else {
        toast.success(variables.add ? t('mods.added') : t('mods.removed'))
      }

      // The whole prefix: a change to the list changes its diagnosis.
      await queryClient.invalidateQueries({ queryKey: ['mods', id] })
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError && error.status === 502
          ? t('mods.transferFailed')
          : t('errors.generic'),
      )
    },
    // Cleared after the refetch, not before: clearing on success alone
    // drops the card to its stale state for one render.
    onSettled: () => setPending(null),
  })

  // Either tab can carry it; whichever loaded first is the same answer.
  const reading = installed.data?.buildReading ?? results.data?.buildReading ?? null

  const installedIds = new Set((installed.data?.items ?? []).map((mod) => mod.workshopId))

  const toggle = (workshopId: string, add: boolean) => {
    setPending(workshopId)
    change.mutate({ workshopId, add })
  }

  const openDetail = (workshopId: string) => {
    void navigate(`/servers/${id}/mods/${workshopId}`)
  }

  const shownInstalled = (installed.data?.items ?? []).filter((mod) => matchesSearch(mod, needle))
  const categories = categoriesOf(results.data?.items ?? [])

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('nav.mods')}</h1>
        <p className="text-muted-foreground">{t('mods.description')}</p>
      </div>

      {/* Stated at the top of both tabs, because it explains every count
          below it: an unfiltered list on an unknown build is not the
          same list as a filtered one. */}
      {reading?.build != null && (
        <p className="text-sm text-muted-foreground">
          {reading.source === 'bridge'
            ? t('mods.buildFromBridge', { version: reading.fullVersion ?? reading.build })
            : t('mods.buildNotice', { build: reading.build })}
        </p>
      )}

      {/* The game says one thing and somebody typed another. Preferring
          one silently would leave the wrong list looking right. */}
      {reading?.disagrees === true && (
        <Alert variant="warning">
          <AlertTitle>{t('mods.buildDisagreesTitle')}</AlertTitle>
          <AlertDescription>
            {t('mods.buildDisagreesBody', {
              reported: reading.reported,
              entered: reading.entered,
            })}
          </AlertDescription>
        </Alert>
      )}

      {reading?.build == null && installed.isSuccess && (
        <Alert>
          <AlertTitle>{t('mods.noBuildTitle')}</AlertTitle>
          <AlertDescription>{t('mods.noBuildBody')}</AlertDescription>
        </Alert>
      )}

      <Tabs value={tab} onValueChange={setTab} className="space-y-4">
        <TabsList>
          <TabsTrigger value="discover">{t('mods.discoverTab')}</TabsTrigger>
          <TabsTrigger value="installed">
            {t('mods.installedTab', { count: installed.data?.items.length ?? 0 })}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="installed" className="space-y-4">
          {installed.isPending ? (
            <Skeleton className="h-64 w-full" />
          ) : installed.data?.state !== 'found' ? (
            <Alert variant="warning">
              <AlertTitle>{t(`mods.file.${installed.data?.state ?? 'noFile'}`)}</AlertTitle>
              <AlertDescription>{t('mods.fileHint')}</AlertDescription>
            </Alert>
          ) : (
            <>
              <DiagnosisCard
                diagnosis={diagnosis.data}
                installed={installed.data?.items ?? []}
                applying={order.isPending}
                onApplyOrder={() => order.mutate()}
              />

              <SearchField
                value={needle}
                onChange={setNeedle}
                placeholder={t('mods.filterInstalled')}
              />

              {shownInstalled.length === 0 ? (
                <Empty>
                  {needle === '' ? t('mods.noneInstalled') : t('mods.noMatches')}
                </Empty>
              ) : (
                <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(min(100%,20rem),1fr))]">
                  {shownInstalled.map((mod) => (
                    <ModTile
                      key={mod.workshopId}
                      mod={mod}
                      installed
                      pending={pending === mod.workshopId}
                      onOpen={() => openDetail(mod.workshopId)}
                      onToggle={() => toggle(mod.workshopId, false)}
                    />
                  ))}
                </div>
              )}
            </>
          )}
        </TabsContent>

        <TabsContent value="discover">
          {/* The search spans the page and the split begins beneath it.
              Side by side, the rail's heading and the search field sat
              at different heights and read as misaligned. */}
          <div className="space-y-4">
            <DiscoverControls
              term={term}
              onTerm={setTerm}
              sort={sort}
              onSort={setSort}
              onAddById={(workshopId) => toggle(workshopId, true)}
            />

            {/* `minmax(0,1fr)` so a long title cannot widen the page
                (rule 10g1). */}
            <div className="grid gap-4 lg:grid-cols-[15rem_minmax(0,1fr)] lg:items-start">
              <CategoryRail
                categories={categories}
                tags={tags}
                onTags={setTags}
                className="lg:sticky lg:top-4"
              />

              <div className="min-w-0 space-y-4">
          {results.data?.state === 'noKey' ? (
            <Alert>
              <KeyRound className="size-4" />
              <AlertTitle>{t('mods.noKeyTitle')}</AlertTitle>
              <AlertDescription>{t('mods.noKeyBody')}</AlertDescription>
            </Alert>
          ) : results.isPending ? (
            <Skeleton className="h-64 w-full" />
          ) : results.data === undefined || results.data.items.length === 0 ? (
            <Empty>{t('mods.noResults')}</Empty>
          ) : (
            <>
              {/* Same height as the rail's heading beside it: both are
                  the first line of their column. */}
              <p className="flex items-center px-2 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase lg:py-1">
                {t('mods.resultCount', {
                  shown: results.data.items.length,
                  total: results.data.total,
                })}
              </p>

              {/* As many columns as fit rather than a ladder of breakpoints:
                      the sidebar and the category rail both eat width, so what
                      matters is the space actually left, not the viewport. */}
                  <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(min(100%,22rem),1fr))]">
                {results.data.items.map((mod) => (
                  <ModTile
                    key={mod.workshopId}
                    mod={mod}
                    installed={installedIds.has(mod.workshopId)}
                    pending={pending === mod.workshopId}
                    onOpen={() => openDetail(mod.workshopId)}
                    onToggle={() =>
                      toggle(mod.workshopId, !installedIds.has(mod.workshopId))
                    }
                  />
                ))}
              </div>
            </>
          )}
              </div>
            </div>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  )
}

/**
 * The categories, as a list rather than a row of chips.
 *
 * Twenty-four of them wrapped to three rows and pushed the mods below
 * the fold; Steam puts them in a rail for the same reason. A count
 * beside each one says how much is behind it before it is clicked.
 */
function CategoryRail({
  categories,
  tags,
  onTags,
  className,
}: {
  categories: { tag: string; count: number }[]
  tags: string[]
  onTags: (value: string[]) => void
  className?: string
}) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)

  if (categories.length < 2) {
    return null
  }

  const active = tags.length === 0 ? t('mods.allCategories') : tags.join(', ')

  return (
    <nav className={cn('space-y-1', className)} aria-label={t('mods.categories')}>
      {/* On a phone a rail is the wrong shape: 24 entries filled the
          screen and the first mod sat below all of them. Collapsed
          there, always open from `lg` where there is a column for it. */}
      <Collapsible open={open} onOpenChange={setOpen}>
        <CollapsibleTrigger className="flex w-full items-center justify-between gap-2 rounded-sm px-2 py-2 text-left hover:bg-accent/50 lg:pointer-events-none lg:py-1">
          <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
            {t('mods.categories')}
          </span>

          <span className="flex min-w-0 items-center gap-1.5 lg:hidden">
            <span className="truncate text-sm text-muted-foreground">{active}</span>
            <ChevronDown
              className={cn('size-4 shrink-0 transition-transform', open && 'rotate-180')}
            />
          </span>
        </CollapsibleTrigger>

        {/* `forceMount` plus `lg:block`: the list has to be there on a
            wide screen whatever the collapsed state says. */}
        <CollapsibleContent forceMount className={cn('lg:block', !open && 'hidden')}>
      {/* Capped and scrollable rather than endless: the rail must not
          push the page taller than the results beside it. */}
      {/* The same 16px the results column puts between its count and
            the grid, so the first category lines up with the first
            tile rather than sitting above it. */}
        <ul className="max-h-[22rem] space-y-0.5 overflow-y-auto pr-1 lg:mt-4 lg:max-h-[28rem]">
        <li>
          <CategoryEntry
            label={t('mods.allCategories')}
            active={tags.length === 0}
            onClick={() => onTags([])}
          />
        </li>

        {categories.map(({ tag, count }) => (
          <li key={tag}>
            <CategoryEntry
              label={tag}
              count={count}
              active={tags.includes(tag)}
              onClick={() =>
                onTags(tags.includes(tag) ? tags.filter((held) => held !== tag) : [...tags, tag])
              }
            />
          </li>
        ))}
      </ul>
        </CollapsibleContent>
      </Collapsible>
    </nav>
  )
}

function CategoryEntry({
  label,
  count,
  active,
  onClick,
}: {
  label: string
  count?: number
  active: boolean
  onClick: () => void
}) {
  return (
    <button
      type="button"
      aria-pressed={active}
      onClick={onClick}
      className={cn(
        'flex w-full items-center justify-between gap-2 rounded-sm px-2 py-2 text-left text-sm transition-colors sm:py-1.5',
        active ? 'bg-accent font-medium text-accent-foreground' : 'hover:bg-accent/50',
      )}
    >
      <span className="min-w-0 truncate">{label}</span>

      {count !== undefined && (
        <span className="shrink-0 font-mono text-xs text-muted-foreground">{count}</span>
      )}
    </button>
  )
}

function SearchField({
  value,
  onChange,
  placeholder,
}: {
  value: string
  onChange: (value: string) => void
  placeholder: string
}) {
  const { t } = useTranslation()

  return (
    <div className="relative h-9 min-w-56 flex-1">
      <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />

      <Input
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        className="pl-8 pr-8"
      />

      {value !== '' && (
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="absolute top-1/2 right-0.5 size-8 -translate-y-1/2"
          onClick={() => onChange('')}
          aria-label={t('common.clear')}
        >
          <X className="size-4" />
        </Button>
      )}
    </div>
  )
}

function DiscoverControls({
  term,
  onTerm,
  sort,
  onSort,
  onAddById,
}: {
  term: string
  onTerm: (value: string) => void
  sort: SortOrder
  onSort: (value: SortOrder) => void
  onAddById: (workshopId: string) => void
}) {
  const { t } = useTranslation()
  // Held against the term it was typed for rather than copied from it
  // by an effect, which would overwrite what somebody is typing on
  // every refetch (rule 10g2).
  const [edit, setEdit] = useState<{ from: string; value: string } | null>(null)

  const draft = edit !== null && edit.from === term ? edit.value : term

  // One field for both jobs: a second box for "paste an id here" was a
  // control the operator had to choose between before typing. What was
  // pasted decides instead — an id or a workshop link adds, anything
  // else searches.
  const pastedId = readWorkshopId(draft)

  return (
    <div className="space-y-3">
      <form
        className="flex flex-wrap gap-2"
        onSubmit={(event) => {
          event.preventDefault()

          if (pastedId !== null) {
            onAddById(pastedId)
            setEdit({ from: term, value: '' })

            return
          }

          onTerm(draft)
        }}
      >
        <SearchField
          value={draft}
          onChange={(value) => setEdit({ from: term, value })}
          placeholder={t('mods.searchOrId')}
        />

        <Button type="submit">
          {pastedId === null ? t('mods.search') : t('mods.addById')}
        </Button>
      </form>

      {/* Said before the click rather than after it, so an operator who
          pasted a link knows what the button will do. */}
      {pastedId !== null && (
        <p className="text-xs text-muted-foreground">
          {t('mods.idRecognised', { id: pastedId })}
        </p>
      )}

      <div className="flex flex-wrap gap-1">
        {SORTS.map((option) => (
          <Button
            key={option}
            type="button"
            variant={sort === option ? 'secondary' : 'ghost'}
            size="sm"
            className="py-2 sm:py-1"
            aria-pressed={sort === option}
            onClick={() => onSort(option)}
          >
            {t(`mods.sort.${option}`)}
          </Button>
        ))}
      </div>
    </div>
  )
}

/**
 * The categories, as a list rather than a row of chips.
 *
 * Twenty-four of them wrapped to three rows and pushed the mods below
 * the fold; Steam puts them in a rail for the same reason. A count
 * beside each one says how much is behind it before it is clicked.
 */
