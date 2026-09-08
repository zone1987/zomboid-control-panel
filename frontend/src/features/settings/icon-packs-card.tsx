import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { CheckCircle2, Image, XCircle } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { DropZone } from '@/components/ui/drop-zone'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  iconStatus,
  uploadIconPacks,
  type IconUploadResult,
  type UploadProgress,
} from '@/features/items/items'

/**
 * Generous rather than protective: packs travel in pieces now, so the
 * request size is no longer the constraint. This only stops somebody
 * dropping a whole game folder in.
 */
const MAX_TOTAL_BYTES = 512 * 1024 * 1024

/** One decimal is enough to see movement without jitter. */
function megabytes(bytes: number): string {
  return (bytes / 1024 / 1024).toFixed(1)
}

export function IconPacksCard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [clear, setClear] = useState(false)
  const [report, setReport] = useState<IconUploadResult | null>(null)
  const [progress, setProgress] = useState<UploadProgress | null>(null)

  const { data: status } = useQuery({ queryKey: ['icon-status'], queryFn: iconStatus })

  const upload = useMutation({
    mutationFn: (files: File[]) => uploadIconPacks(files, clear, setProgress),
    onSuccess: (result) => {
      setReport(result)
      setProgress(null)
      void queryClient.invalidateQueries({ queryKey: ['icon-status'] })
      void queryClient.invalidateQueries({ queryKey: ['items'] })

      const worked = result.results.filter((entry) => !entry.failed).length

      if (worked === 0) {
        toast.error(t('settings.icons.noneWorked'))

        return
      }

      toast.success(t('settings.icons.done', { count: result.count }))
    },
    onError: (error) => {
      setProgress(null)
      toast.error(
        error instanceof ApiError && error.status === 413
          ? t('settings.icons.tooLarge')
          : t('errors.generic'),
      )
    },
  })

  const choose = (files: File[]) => {
    if (files.length === 0) {
      return
    }

    setReport(null)

    const total = files.reduce((sum, file) => sum + file.size, 0)

    if (total > MAX_TOTAL_BYTES) {
      toast.error(t('settings.icons.tooLarge'))

      return
    }

    upload.mutate(files)
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settings.icons.title')}</CardTitle>
        <CardDescription>{t('settings.icons.description')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <Alert>
          <Image className="size-4" />
          <AlertTitle>
            {status?.available === true
              ? t('settings.icons.have', { count: status.count })
              : t('settings.icons.haveNone')}
          </AlertTitle>
          <AlertDescription>
            {/* Once the icons are there, naming the files reads as a
                demand rather than as a note for later. */}
            {status?.available === true ? (
              <p>{t('settings.icons.ready')}</p>
            ) : (
              <>
                <p>{t('settings.icons.where')}</p>
                <p className="font-mono text-xs">{(status?.wanted ?? []).join(' · ')}</p>
              </>
            )}
          </AlertDescription>
        </Alert>

        <div className="flex items-center gap-2">
          <Switch id="icons-clear" checked={clear} onCheckedChange={setClear} />
          <Label htmlFor="icons-clear" className="font-normal">
            {t('settings.icons.clearFirst')}
          </Label>
        </div>

        <DropZone
          accept=".pack"
          disabled={upload.isPending}
          onFiles={choose}
        >
          <span className="text-sm font-medium">
            {upload.isPending ? t('settings.icons.working') : t('settings.icons.drop')}
          </span>
          <span className="text-xs text-muted-foreground">{t('settings.icons.dropHint')}</span>
        </DropZone>

        {progress !== null && (
          <div className="space-y-1.5">
            <div className="flex items-baseline justify-between gap-2 text-sm">
              <span className="min-w-0 truncate font-medium">{progress.name}</span>
              <span className="shrink-0 text-muted-foreground text-xs">
                {t('settings.icons.ofFiles', {
                  index: progress.index,
                  files: progress.total,
                })}
              </span>
            </div>

            <div
              className="h-2 overflow-hidden rounded-full bg-muted"
              role="progressbar"
              aria-valuemin={0}
              aria-valuemax={progress.totalBytes}
              aria-valuenow={progress.sentBytes}
              aria-label={t('settings.icons.working')}
            >
              <div
                className="h-full rounded-full bg-primary transition-[width] duration-200"
                style={{
                  width: `${progress.totalBytes === 0 ? 0 : Math.round((progress.sentBytes / progress.totalBytes) * 100)}%`,
                }}
              />
            </div>

            <p className="text-xs text-muted-foreground">
              {t('settings.icons.sent', {
                sent: megabytes(progress.sentBytes),
                size: megabytes(progress.totalBytes),
              })}
            </p>
          </div>
        )}

        {upload.isPending && progress === null && (
          <p className="text-xs text-muted-foreground">{t('settings.icons.patience')}</p>
        )}

        {report !== null && (
          <ul className="space-y-1.5 rounded-md border p-3 text-sm">
            {report.results.map((entry) => (
              <li key={entry.name} className="flex items-start gap-2">
                {entry.failed ? (
                  <XCircle className="mt-0.5 size-4 shrink-0 text-destructive" />
                ) : (
                  <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-emerald-500" />
                )}

                <span className="min-w-0 flex-1">
                  <span className="font-medium">{entry.name}</span>{' '}
                  <span className="text-muted-foreground">
                    {entry.failed
                      ? t(entry.error ?? 'errors.generic', {
                          defaultValue: entry.detail ?? '',
                        })
                      : t('settings.icons.extracted', {
                          count: entry.extracted ?? 0,
                          pages: entry.pages ?? 0,
                        })}
                  </span>
                </span>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
