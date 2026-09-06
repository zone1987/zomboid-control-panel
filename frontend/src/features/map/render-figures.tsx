import { useTranslation } from 'react-i18next'
import { TriangleAlert } from 'lucide-react'

import type { RenderProgress } from '@/features/settings/render-progress'
import { throughput } from './render-estimate'
import { size } from './render-format'

/**
 * Everything the run knows, for whoever wants it.
 *
 * Shown only when the window is open: seven figures at equal weight is
 * what the previous window did, and it made none of them findable.
 * Counts that mean nothing yet stay away entirely rather than sitting
 * at zero -- a permanent "0 failures" teaches the eye to skip the line
 * where a twelve would appear.
 */
export function RenderFigures({ progress }: { progress: RenderProgress }) {
  const { t } = useTranslation()
  const rate = throughput(progress)

  const surveying = progress.phase === 'surveying'
  const failed = progress.tilesFailed ?? 0
  const retryRound = progress.retryRound ?? 0
  const skippedEmpty = progress.passesSkippedEmpty ?? 0

  return (
    <div className="space-y-2">
      {rate !== null && (
        <p className="tabular-nums text-xs text-muted-foreground">
          {t('map.render.rate', {
            tiles: rate.tilesPerSecond.toFixed(1),
            bytes: (rate.bytesPerSecond / 1048576).toFixed(1),
          })}
        </p>
      )}

      <dl className="grid grid-cols-2 gap-x-4 gap-y-1.5 text-xs">
        {surveying ? (
          <>
            <Figure
              label={t('map.render.surveyed')}
              value={`${(progress.cellsSurveyed ?? 0).toLocaleString()} / ${(78 * 64).toLocaleString()}`}
            />
            <Figure
              label={t('map.render.withContent')}
              value={(progress.cellsWithContent ?? 0).toLocaleString()}
            />
          </>
        ) : (
          <>
            <Figure
              label={t('map.render.totalDone')}
              value={
                (progress.tilesEstimated ?? 0) > 0
                  ? `${(progress.tilesUploaded ?? 0).toLocaleString()} / ≈ ${(progress.tilesEstimated ?? 0).toLocaleString()}`
                  : (progress.tilesUploaded ?? 0).toLocaleString()
              }
            />
            <Figure label={t('map.render.inStore')} value={size(progress.bytesHeld ?? 0)} />
            <Figure
              label={t('settings.render.cells')}
              value={`${(progress.cellsDone ?? 0).toLocaleString()} / ${(progress.cellsTotal ?? 0).toLocaleString()}`}
            />
            <Figure
              label={t('map.render.floor')}
              value={progress.currentFloor === undefined ? '—' : String(progress.currentFloor)}
            />
          </>
        )}
      </dl>

      {/* What the survey saved, and the reason it was worth 50 minutes
          of FTP: four cell-floor passes in five hold nothing at all. */}
      {skippedEmpty > 0 && (
        <p className="text-xs text-muted-foreground">
          {t('map.render.skippedEmptyNote', { count: skippedEmpty })}
        </p>
      )}

      {retryRound > 0 && (
        <p className="flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-500">
          <TriangleAlert className="size-3.5 shrink-0" />
          {t('map.render.retryNote', {
            round: retryRound,
            count: progress.retryPending ?? 0,
          })}
        </p>
      )}

      {failed > 0 && (
        <p className="flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-500">
          <TriangleAlert className="size-3.5 shrink-0" />
          {t('map.render.failedNote', { count: failed })}
        </p>
      )}

      {/* The name going past is how somebody tells a working render
          from a stuck one. */}
      <p className="truncate rounded-md bg-muted/60 px-2 py-1 font-mono text-[0.65rem] text-muted-foreground">
        {progress.currentCell ? `${progress.currentCell} · ` : ''}
        {progress.currentTile || '…'}
      </p>
    </div>
  )
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <div className="min-w-0">
      <dt className="truncate text-[0.65rem] text-muted-foreground">{label}</dt>
      <dd className="truncate tabular-nums">{value}</dd>
    </div>
  )
}
