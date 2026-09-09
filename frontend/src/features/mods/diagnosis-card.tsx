import { useTranslation } from 'react-i18next'
import { AlertTriangle, ArrowDownUp, Check, HelpCircle, Link2Off, PackageX } from 'lucide-react'

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { hasFindings, type Mod, type ModDiagnosis } from './mods'

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
}: {
  diagnosis: ModDiagnosis | undefined
  installed: Mod[]
  onApplyOrder: () => void
  applying: boolean
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
          <Finding
            icon={<Link2Off className="size-4" />}
            title={t('mods.orphanedTitle', { count: diagnosis.orphanedModIds.length })}
            body={t('mods.orphanedBody')}
            entries={diagnosis.orphanedModIds}
          />
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
