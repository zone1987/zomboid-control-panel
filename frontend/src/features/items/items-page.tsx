import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useParams, useSearchParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { LayoutGrid, List, Package, PackagePlus, Search, Trash2, Users, X } from 'lucide-react'

import { cn } from '@/lib/utils'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Copyable } from '@/components/ui/copyable'
import { Skeleton } from '@/components/ui/skeleton'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { getServer } from '@/features/servers/servers'
import { listPlayers } from '@/features/players/players'
import { ItemTile } from './item-tile'
import { ItemRow } from './item-row'
import {
  displayName,
  giveItems,
  listItems,
  matchesSearch,
  splitSearch,
  type Item,
} from './items'

/**
 * Five thousand tiles at once locks the browser, so the list grows as it
 * is scrolled. The search narrows it first, which is what makes the
 * catalogue usable at all.
 */
const PAGE_SIZE = 200

export function ItemsPage() {
  const { t, i18n } = useTranslation()
  const { id = '' } = useParams()
  const [needle, setNeedle] = useState('')
  const [view, setView] = useState<'grid' | 'list'>('grid')
  const [selection, setSelection] = useState<Record<string, number>>({})
  // Arriving from a player's context menu picks them straight away.
  const [search] = useSearchParams()
  const [player, setPlayer] = useState<string | null>(search.get('player'))
  const sentinel = useRef<HTMLDivElement>(null)

  const { data: server } = useQuery({ queryKey: ['server', id], queryFn: () => getServer(id) })

  // Keyed by language: switching it fetches names in the new one, and
  // the browser keeps both.
  const { data: catalogue, isPending } = useQuery({
    queryKey: ['items', id, i18n.language],
    queryFn: () => listItems(id, i18n.language),
    retry: false,
    staleTime: Number.POSITIVE_INFINITY,
  })

  const { data: players } = useQuery({
    queryKey: ['players', id, false],
    queryFn: () => listPlayers(id, false),
    retry: false,
    refetchInterval: 5_000,
    refetchIntervalInBackground: true,
    placeholderData: (previous) => previous,
  })

  const terms = useMemo(() => splitSearch(needle), [needle])

  const matches = useMemo(
    () => (catalogue?.items ?? []).filter((item) => matchesSearch(item, terms)),
    [catalogue, terms],
  )

  // A new search starts at the top rather than deep in the previous list.
  // The page size resets when the list changes underneath it, derived
  // rather than set in an effect: an effect renders the long list once
  // with the old count and again with the new one.
  const [paging, setPaging] = useState({ from: `${needle}|${view}`, shown: PAGE_SIZE })
  const visible = paging.from === `${needle}|${view}` ? paging.shown : PAGE_SIZE

  // Updated from the previous value rather than from `visible`, because
  // the observer's effect captures whatever it closed over and would
  // otherwise keep adding to the same stale count. Stable across
  // renders, so the effect below can depend on it without re-observing
  // on every keystroke.
  const showMore = useCallback(() => {
    setPaging((previous) => {
      const key = `${needle}|${view}`
      const shown = previous.from === key ? previous.shown : PAGE_SIZE

      return { from: key, shown: shown + PAGE_SIZE }
    })
  }, [needle, view])

  useEffect(() => {
    const element = sentinel.current

    if (element === null) {
      return
    }

    const observer = new IntersectionObserver((entries) => {
      if (entries[0]?.isIntersecting === true) {
        showMore()
      }
    })

    observer.observe(element)

    return () => observer.disconnect()
  }, [matches.length, showMore])

  const online = (players?.items ?? []).filter((entry) => entry.online)
  const chosen = Object.entries(selection).filter(([, count]) => count > 0)

  const byType = useMemo(() => {
    const map = new Map<string, Item>()

    for (const item of catalogue?.items ?? []) {
      map.set(item.type, item)
    }

    return map
  }, [catalogue])

  const setCount = (type: string, count: number) =>
    setSelection((previous) => {
      const next = { ...previous }
      const capped = Math.max(0, Math.min(catalogue?.limits.maxTotal ?? 1000, count))

      if (capped === 0) {
        delete next[type]
      } else {
        next[type] = capped
      }

      return next
    })

  const give = useMutation({
    mutationFn: () =>
      giveItems(
        id,
        player ?? '',
        chosen.map(([type, count]) => ({ type, count })),
      ),
    onSuccess: (result) => {
      const failed = result.results.filter((entry) => entry.failed)

      if (failed.length > 0) {
        toast.error(t('items.someFailed', { count: failed.length }), {
          description: failed.map((entry) => entry.type).join(', '),
        })

        return
      }

      setSelection({})
      toast.success(t('items.given', { count: result.results.length, player: result.username }))
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError && error.status === 502 ? t('items.rconFailed') : t('errors.generic'),
      )
    },
  })

  if (isPending) {
    return <Skeleton className="h-96 w-full" />
  }

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold">{t('items.title')}</h1>
        <p className="text-muted-foreground">
          {server ? t('items.descriptionFor', { server: server.name }) : t('items.description')}
        </p>
      </div>

      {catalogue?.available !== true ? (
        <Alert>
          <AlertTitle>{t('items.noCatalogue')}</AlertTitle>
          <AlertDescription>{t('items.noCatalogueHint')}</AlertDescription>
        </Alert>
      ) : (
        <div className="grid gap-4 lg:grid-cols-[1fr_18rem]">
          <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
            <div className="relative h-9 min-w-56 flex-1">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                autoFocus
                value={needle}
                className="pl-8 pr-8"
                placeholder={t('items.search')}
                onChange={(event) => setNeedle(event.target.value)}
              />
              {needle !== '' && (
                <button
                  type="button"
                  className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  aria-label={t('common.cancel')}
                  onClick={() => setNeedle('')}
                >
                  <X className="size-4" />
                </button>
              )}
            </div>

            <div className="flex rounded-md border">
              <Button
                variant={view === 'grid' ? 'secondary' : 'ghost'}
                size="icon"
                className="size-9 rounded-r-none"
                aria-label={t('items.gridView')}
                onClick={() => setView('grid')}
              >
                <LayoutGrid className="size-4" />
              </Button>
              <Button
                variant={view === 'list' ? 'secondary' : 'ghost'}
                size="icon"
                className="size-9 rounded-l-none"
                aria-label={t('items.listView')}
                onClick={() => setView('list')}
              >
                <List className="size-4" />
              </Button>
            </div>

              <span className="text-xs text-muted-foreground">
                {terms.length === 0
                  ? t('items.count', { count: matches.length })
                  : t('items.matchCount', { shown: matches.length, total: catalogue.items.length })}
              </span>
            </div>

            {matches.length === 0 ? (
              <p className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">
                {t('items.noMatch')}
              </p>
            ) : (
              <>
                <div
                  className={
                    view === 'grid'
                      // A floor, then share out what is left: the row
                      // fills the width, and a wider screen gets more
                      // tiles rather than fatter ones.
                      ? 'grid grid-cols-[repeat(auto-fill,minmax(9.5rem,1fr))] gap-2'
                      : 'space-y-1.5'
                  }
                >
                  {matches.slice(0, visible).map((item) =>
                    view === 'grid' ? (
                      <ItemTile
                        key={item.type}
                        item={item}
                        count={selection[item.type] ?? 0}
                        onChange={(count) => setCount(item.type, count)}
                      />
                    ) : (
                      <ItemRow
                        key={item.type}
                        item={item}
                        count={selection[item.type] ?? 0}
                        onChange={(count) => setCount(item.type, count)}
                      />
                    ),
                  )}
                </div>

                {visible < matches.length && <div ref={sentinel} className="h-8" />}
              </>
            )}
          </div>

          <div className="space-y-4 lg:sticky lg:top-4 lg:self-start">
            <section className="rounded-md border">
              <header className="flex items-center gap-2 border-b px-3 py-2">
                <Users className="size-4 text-muted-foreground" />
                <h2 className="text-sm font-medium">{t('items.players')}</h2>
              </header>

              <div className="max-h-56 overflow-y-auto p-1.5">
                {online.length === 0 ? (
                  <p className="p-2 text-xs text-muted-foreground">{t('items.noPlayers')}</p>
                ) : (
                  online.map((entry) => (
                    <button
                      key={entry.username}
                      type="button"
                      className={cn(
                        'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm',
                        player === entry.username
                          ? 'bg-primary/10 font-medium'
                          : 'hover:bg-accent hover:text-accent-foreground',
                      )}
                      onClick={() => setPlayer(entry.username)}
                    >
                      <span aria-hidden className="size-2 shrink-0 rounded-full bg-emerald-500" />
                      <span className="truncate">{entry.username}</span>
                    </button>
                  ))
                )}
              </div>
            </section>

            <section className="rounded-md border">
              <header className="flex items-center gap-2 border-b px-3 py-2">
                <Package className="size-4 text-muted-foreground" />
                <h2 className="flex-1 text-sm font-medium">{t('items.selected')}</h2>

                {chosen.length > 0 && (
                  <Button
                    variant="ghost"
                    size="icon"
                    className="size-6"
                    aria-label={t('items.clear')}
                    onClick={() => setSelection({})}
                  >
                    <Trash2 className="size-3.5" />
                  </Button>
                )}
              </header>

              <div className="max-h-64 overflow-y-auto p-1.5">
                {chosen.length === 0 ? (
                  <p className="p-2 text-xs text-muted-foreground">{t('items.noneSelected')}</p>
                ) : (
                  chosen.map(([type, count]) => (
                    <div key={type} className="flex items-center gap-2 px-2 py-1 text-sm">
                      <Badge variant="secondary" className="shrink-0 tabular-nums">
                        {count}
                      </Badge>
                      <div className="min-w-0 flex-1">
                        <p className="truncate">
                          {byType.has(type) ? displayName(byType.get(type) as Item) : type}
                        </p>
                        <Copyable value={type} className="max-w-full" />
                      </div>
                      <button
                        type="button"
                        className="shrink-0 text-muted-foreground hover:text-destructive"
                        aria-label={t('items.remove')}
                        onClick={() => setCount(type, 0)}
                      >
                        <X className="size-3.5" />
                      </button>
                    </div>
                  ))
                )}
              </div>

              <div className="border-t p-2">
                <Button
                  className="w-full"
                  disabled={player === null || chosen.length === 0 || give.isPending}
                  onClick={() => give.mutate()}
                >
                  <PackagePlus className="size-4" />
                  {give.isPending
                    ? t('common.loading')
                    : player === null
                      ? t('items.choosePlayer')
                      : t('items.giveTo', { player })}
                </Button>
              </div>
            </section>
          </div>
        </div>
      )}
    </div>
  )
}
