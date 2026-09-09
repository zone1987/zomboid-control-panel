import { useNavigate, useParams } from 'react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import {
  ArrowLeft,
  Check,
  ExternalLink,
  Eye,
  Heart,
  Link2,
  Map,
  Plus,
  Trash2,
  TriangleAlert,
  Users,
} from 'lucide-react'

import { ApiError } from '@/lib/api'
import { formatDate } from '@/lib/dates'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Empty } from '@/components/ui/empty'
import { Skeleton } from '@/components/ui/skeleton'
import { ModCover } from './mod-cover'
import { BbcodeText } from './bbcode-text'
import { DependencyTree } from './dependency-tree'
import {
  addMod,
  displayName,
  formatSize,
  listInstalled,
  modDetail,
  removeMod,
  type DependencyTree as DependencyTreeShape,
} from './mods'

export function ModDetailPage() {
  const { t, i18n } = useTranslation()
  const { id = '', workshopId = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const detail = useQuery({
    queryKey: ['mods', id, 'detail', workshopId],
    queryFn: () => modDetail(id, workshopId),
  })

  const installed = useQuery({
    queryKey: ['mods', id, 'installed'],
    queryFn: () => listInstalled(id),
  })

  const isInstalled = (installed.data?.items ?? []).some(
    (mod) => mod.workshopId === workshopId,
  )

  const change = useMutation({
    mutationFn: (add: boolean) => (add ? addMod(id, workshopId) : removeMod(id, workshopId)),
    onSuccess: async (result, add) => {
      if (result.status === 'keysMissing') {
        toast.error(t('mods.keysMissing', { keys: result.missingKeys.join(', ') }))
      } else if (result.status === 'notVerified') {
        toast.error(t('mods.notVerified'))
      } else {
        toast.success(add ? t('mods.added') : t('mods.removed'))
      }

      await queryClient.invalidateQueries({ queryKey: ['mods', id, 'installed'] })
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError && error.status === 502
          ? t('mods.transferFailed')
          : t('errors.generic'),
      )
    },
  })

  if (detail.isPending) {
    return <Skeleton className="h-96 w-full" />
  }

  const mod = detail.data?.item ?? null

  if (mod === null) {
    return (
      <div className="space-y-4">
        <BackLink onClick={() => void navigate(`/servers/${id}/mods`)} />
        <Empty>{t(`mods.lookup.${detail.data?.state ?? 'notFound'}`)}</Empty>
      </div>
    )
  }

  const size = formatSize(mod.fileSize)

  return (
    <div className="space-y-4">
      <BackLink onClick={() => void navigate(`/servers/${id}/mods`)} />

      {/* minmax(0,1fr) rather than 1fr: a long unbroken word in the
          description would otherwise widen the whole page (rule 10g1). */}
      <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div className="space-y-4">
          <Card>
            <CardHeader className="flex flex-row items-start gap-4">
              <ModCover mod={mod} className="size-20 shrink-0" />

              <div className="min-w-0 flex-1 space-y-2">
                <CardTitle className="break-words">{displayName(mod)}</CardTitle>

                <div className="flex flex-wrap gap-1">
                  {mod.buildVerdict === 'mismatch' && (
                    <Badge variant="warning" className="gap-1 text-xs">
                      <TriangleAlert className="size-3" />
                      {t('mods.buildMismatch', { builds: mod.declaredBuilds.join(', ') })}
                    </Badge>
                  )}

                  {mod.isMap && (
                    <Badge variant="outline" className="gap-1 text-xs">
                      <Map className="size-3" />
                      {t('mods.isMap')}
                    </Badge>
                  )}

                  {mod.tags.map((tag) => (
                    <Badge key={tag} variant="secondary" className="text-xs font-normal">
                      {tag}
                    </Badge>
                  ))}
                </div>
              </div>
            </CardHeader>

            <CardContent>
              <BbcodeText text={mod.description ?? ''} />
            </CardContent>
          </Card>

          {mod.isMap && (
            <Alert variant="warning">
              <Map className="size-4" />
              <AlertTitle>{t('mods.mapNoticeTitle')}</AlertTitle>
              <AlertDescription>{t('mods.mapNoticeBody')}</AlertDescription>
            </Alert>
          )}
        </div>

        <div className="space-y-4 lg:sticky lg:top-4 lg:self-start">
          <Card>
            <CardContent className="space-y-3 pt-6">
              <Button
                type="button"
                variant={isInstalled ? 'outline' : 'default'}
                className="w-full"
                disabled={change.isPending}
                onClick={() => change.mutate(!isInstalled)}
              >
                {isInstalled ? (
                  <>
                    <Trash2 className="size-4" />
                    {t('mods.remove')}
                  </>
                ) : (
                  <>
                    <Plus className="size-4" />
                    {t('mods.add')}
                  </>
                )}
              </Button>

              {isInstalled && (
                <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                  <Check className="size-4 text-primary" />
                  {t('mods.installed')}
                </p>
              )}

              <dl className="space-y-1.5 text-sm">
                <Fact label={t('mods.workshopId')} value={mod.workshopId} mono />
                {size !== null && <Fact label={t('mods.size')} value={size} />}
                {mod.updatedAt !== null && (
                  <Fact
                    label={t('mods.updated')}
                    value={formatDate(mod.updatedAt, i18n.language)}
                  />
                )}
                {mod.createdAt !== null && (
                  <Fact
                    label={t('mods.published')}
                    value={formatDate(mod.createdAt, i18n.language)}
                  />
                )}
              </dl>

              <div className="flex flex-wrap gap-3 text-sm text-muted-foreground">
                {mod.subscriptions !== null && (
                  <span className="flex items-center gap-1">
                    <Users className="size-3.5" />
                    {mod.subscriptions.toLocaleString()}
                  </span>
                )}
                {mod.favourites !== null && (
                  <span className="flex items-center gap-1">
                    <Heart className="size-3.5" />
                    {mod.favourites.toLocaleString()}
                  </span>
                )}
                {mod.views !== null && (
                  <span className="flex items-center gap-1">
                    <Eye className="size-3.5" />
                    {mod.views.toLocaleString()}
                  </span>
                )}
              </div>

              {/* Comments and discussions have no API, so they stay on
                  Steam rather than being scraped into a page that would
                  break whenever Valve changed their markup. */}
              <a
                href={mod.url}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-1.5 text-sm text-primary hover:underline"
              >
                {t('mods.openOnSteam')}
                <ExternalLink className="size-3" />
              </a>
            </CardContent>
          </Card>

          <DependencyCard
            tree={detail.data?.tree ?? null}
            hasKey={detail.data?.hasKey ?? false}
            installedIds={new Set((installed.data?.items ?? []).map((entry) => entry.workshopId))}
            onOpen={(dependencyId) => void navigate(`/servers/${id}/mods/${dependencyId}`)}
          />
        </div>
      </div>
    </div>
  )
}

function DependencyCard({
  tree,
  hasKey,
  installedIds,
  onOpen,
}: {
  tree: DependencyTreeShape | null
  hasKey: boolean
  installedIds: Set<string>
  onOpen: (workshopId: string) => void
}) {
  const { t } = useTranslation()
  const children = tree?.nodes[0]?.children ?? []

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Link2 className="size-4" />
          {t('mods.dependencies')}
        </CardTitle>
      </CardHeader>

      <CardContent className="space-y-3">
        {!hasKey ? (
          <p className="text-sm text-muted-foreground">{t('mods.dependenciesNeedKey')}</p>
        ) : children.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('mods.noDependencies')}</p>
        ) : (
          <>
            <DependencyTree
              nodes={tree?.nodes ?? []}
              installedIds={installedIds}
              onOpen={onOpen}
            />

            {tree?.truncated === true && (
              <p className="text-xs text-muted-foreground">{t('mods.truncated')}</p>
            )}
          </>
        )}

        {/* Steam's dependency links are maintained by hand, so the panel
            says what Steam knows rather than claiming completeness. */}
        <p className="text-xs text-muted-foreground">{t('mods.dependenciesCaveat')}</p>
      </CardContent>
    </Card>
  )
}

function Fact({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <div className="flex justify-between gap-3">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className={mono === true ? 'font-mono text-xs' : ''}>{value}</dd>
    </div>
  )
}

function BackLink({ onClick }: { onClick: () => void }) {
  const { t } = useTranslation()

  return (
    <Button type="button" variant="ghost" size="sm" className="-ml-2 gap-1.5" onClick={onClick}>
      <ArrowLeft className="size-4" />
      {t('mods.backToList')}
    </Button>
  )
}
