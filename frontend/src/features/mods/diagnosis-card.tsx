import { useTranslation } from 'react-i18next'
import {
  AlertTriangle,
  ArrowDownUp,
  ArrowUpCircle,
  Check,
  HardDrive,
  HelpCircle,
  Link2Off,
  Map,
  PackageX,
} from 'lucide-react'

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { formatSize, hasFindings, type Mod, type ModDiagnosis } from './mods'

/**
 * What is wrong with the mod list, if anything.
 *
 * Every finding here is a **silent** fault: nothing crashes, nothing is
 * logged, the mod simply does not do what the operator expects. That is
 * why the card exists at all — these are exactly the states somebody
 * would otherwise spend an evening on.
 */
export function DiagnosisCard({
  diagnosis,
  installed,
  onApplyOrder,
  applying,
  onListMaps,
  listingMaps,
  onRepair,
  repairing,
}: {
  diagnosis: ModDiagnosis | undefined
  installed: Mod[]
  onApplyOrder: () => void
  applying: boolean
  onListMaps: () => void
  listingMaps: boolean
  onRepair: () => void
  repairing: boolean
}) {
  const { t } = useTranslation()

  if (diagnosis === undefined || diagnosis.state !== 'found') {
    return null
  }

  // Nothing to say is worth saying once, quietly: an operator who looked
  // should learn that the list is sound, not find an empty space.
  if (!hasFindings(diagnosis)) {
    return (
      <p className="flex items-center gap-2 text-sm text-muted-foreground">
        <Check className="size-4 text-primary" />
        {t('mods.diagnosisClean')}
      </p>
    )
  }

  const fixable = Object.entries(diagnosis.fixableModIds)

  const titleOf = (workshopId: string) =>
    installed.find((mod) => mod.workshopId === workshopId)?.title ?? workshopId

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <AlertTriangle className="size-4" />
          {t('mods.diagnosisTitle')}
        </CardTitle>
      </CardHeader>

      <CardContent className="space-y-4">
        {/* An update is news rather than a fault, so it is not dressed
            as a warning — and there is nothing to press: the server
            fetches it at its next start by itself. */}
        {diagnosis.updates.length > 0 && (
          <Alert>
            <ArrowUpCircle className="size-4" />
            <AlertTitle>{t('mods.updatesTitle', { count: diagnosis.updates.length })}</AlertTitle>
            <AlertDescription className="space-y-2">
              <p>{t('mods.updatesBody')}</p>
              <div className="flex flex-wrap gap-1">
                {diagnosis.updates.map((workshopId) => (
                  <Badge key={workshopId} variant="outline" className="max-w-full text-xs">
                    <span className="truncate">{titleOf(workshopId)}</span>
                  </Badge>
                ))}
              </div>
            </AlertDescription>
          </Alert>
        )}

        {diagnosis.loadOrder.state === 'cycle' && (
          <Alert variant="destructive">
            <Link2Off className="size-4" />
            <AlertTitle>{t('mods.cycleTitle')}</AlertTitle>
            <AlertDescription className="space-y-2">
              <p>{t('mods.cycleBody')}</p>
              <div className="flex flex-wrap gap-1">
                {diagnosis.loadOrder.tangled.map((id) => (
                  <Badge key={id} variant="outline" className="font-mono text-xs">
                    {id}
                  </Badge>
                ))}
              </div>
            </AlertDescription>
          </Alert>
        )}

        {diagnosis.loadOrder.state === 'sorted' && diagnosis.loadOrder.changed && (
          <Alert variant="warning">
            <ArrowDownUp className="size-4" />
            <AlertTitle>{t('mods.orderTitle')}</AlertTitle>
            <AlertDescription className="space-y-3">
              <p>{t('mods.orderBody')}</p>

              {/* The new order shown before it is applied: the file
                  decides whether the server starts, so "show what will
                  happen" weighs more here than elsewhere. */}
              <ol className="space-y-0.5 font-mono text-xs">
                {diagnosis.loadOrder.order.map((id, index) => (
                  <li key={id} className="flex gap-2">
                    <span className="w-5 shrink-0 text-right text-muted-foreground">
                      {index + 1}.
                    </span>
                    <span className="break-all">{id}</span>
                  </li>
                ))}
              </ol>

              <Button type="button" size="sm" disabled={applying} onClick={onApplyOrder}>
                {t('mods.applyOrder')}
              </Button>
            </AlertDescription>
          </Alert>
        )}

        {/* A map mod loads like any other and stays invisible: the
            folder has to be named in Map= as well. Worth its own
            action, since the panel knows the exact name. */}
        {diagnosis.unlistedMaps.length > 0 && (
          <Alert variant="warning">
            <Map className="size-4" />
            <AlertTitle>{t('mods.unlistedMapTitle', { count: diagnosis.unlistedMaps.length })}</AlertTitle>
            <AlertDescription className="space-y-3">
              <p>{t('mods.unlistedMapBody')}</p>

              <div className="flex flex-wrap gap-1">
                {diagnosis.unlistedMaps.map((map) => (
                  <Badge key={map} variant="outline" className="text-xs">
                    {map}
                  </Badge>
                ))}
              </div>

              <Button type="button" size="sm" disabled={listingMaps} onClick={onListMaps}>
                {t('mods.addToMapLine')}
              </Button>
            </AlertDescription>
          </Alert>
        )}

        {diagnosis.missingDependencies.length > 0 && (
          <Finding
            icon={<PackageX className="size-4" />}
            title={t('mods.missingTitle', { count: diagnosis.missingDependencies.length })}
            body={t('mods.missingBody')}
            entries={diagnosis.missingDependencies}
          />
        )}

        {diagnosis.unmappedWorkshopIds.length > 0 && (
          <Finding
            icon={<HelpCircle className="size-4" />}
            title={t('mods.unmappedTitle', { count: diagnosis.unmappedWorkshopIds.length })}
            body={t('mods.unmappedBody')}
            entries={diagnosis.unmappedWorkshopIds.map(titleOf)}
          />
        )}

        {diagnosis.orphanedModIds.length > 0 && (
          <Alert variant="warning">
            <Link2Off className="size-4" />
            <AlertTitle>
              {t('mods.orphanedTitle', { count: diagnosis.orphanedModIds.length })}
            </AlertTitle>
            <AlertDescription className="space-y-3">
              <p>{t('mods.orphanedBody')}</p>

              <div className="flex flex-wrap gap-1">
                {diagnosis.orphanedModIds.map((modId) => (
                  <Badge key={modId} variant="outline" className="max-w-full font-mono text-xs">
                    <span className="truncate">{modId}</span>
                  </Badge>
                ))}
              </div>

              {/* Only where a leading slash explains it. An entry that
                  is simply wrong stays reported and unrepaired: the
                  panel cannot know what was meant. */}
              {fixable.length > 0 && (
                <>
                  <p>
                    {t('mods.slashBody', {
                      count: fixable.length,
                      corrections: fixable
                        .map(([wrong, right]) => `${wrong} → ${right}`)
                        .join(', '),
                    })}
                  </p>

                  <Button type="button" size="sm" disabled={repairing} onClick={onRepair}>
                    {t('mods.repairSlashes', { count: fixable.length })}
                  </Button>
                </>
              )}
            </AlertDescription>
          </Alert>
        )}

        {/* Not a fault either: Steam simply never deletes what leaves
            the list. Worth saying because it costs disk quietly. */}
        {diagnosis.leftOver.length > 0 && (
          <Alert>
            <HardDrive className="size-4" />
            <AlertTitle>{t('mods.leftOverTitle', { count: diagnosis.leftOver.length })}</AlertTitle>
            <AlertDescription className="space-y-2">
              <p>
                {t('mods.leftOverBody', {
                  size: formatSize(
                    diagnosis.leftOver.reduce(
                      (total, workshopId) =>
                        total + (diagnosis.manifest?.items[workshopId]?.size ?? 0),
                      0,
                    ),
                  ) ?? '',
                })}
              </p>
              <div className="flex flex-wrap gap-1">
                {diagnosis.leftOver.map((workshopId) => (
                  <Badge key={workshopId} variant="outline" className="font-mono text-xs">
                    {workshopId}
                  </Badge>
                ))}
              </div>
            </AlertDescription>
          </Alert>
        )}

        {/* An incomplete walk presented as complete would say the list is
            satisfied when it may not be. */}
        {diagnosis.truncated && (
          <p className="text-xs text-muted-foreground">{t('mods.truncated')}</p>
        )}
      </CardContent>
    </Card>
  )
}

function Finding({
  icon,
  title,
  body,
  entries,
}: {
  icon: React.ReactNode
  title: string
  body: string
  entries: string[]
}) {
  return (
    <Alert variant="warning">
      {icon}
      <AlertTitle>{title}</AlertTitle>
      <AlertDescription className="space-y-2">
        <p>{body}</p>
        <div className="flex flex-wrap gap-1">
          {entries.map((entry) => (
            <Badge key={entry} variant="outline" className="max-w-full text-xs">
              <span className="truncate">{entry}</span>
            </Badge>
          ))}
        </div>
      </AlertDescription>
    </Alert>
  )
}
