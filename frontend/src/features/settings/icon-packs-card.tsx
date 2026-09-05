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
import { iconStatus, uploadIconPacks, type IconUploadResult } from '@/features/items/items'

/**
 * Texture packs are large -- UI2.pack alone is around 50 MB -- and PHP
 * refuses a request above post_max_size outright, which arrives as an
 * empty reply rather than an error worth reading.
 */
const MAX_TOTAL_BYTES = 95 * 1024 * 1024

export function IconPacksCard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [clear, setClear] = useState(false)
  const [report, setReport] = useState<IconUploadResult | null>(null)

  const { data: status } = useQuery({ queryKey: ['icon-status'], queryFn: iconStatus })

  const upload = useMutation({
    mutationFn: (files: File[]) => uploadIconPacks(files, clear),
    onSuccess: (result) => {
      setReport(result)
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

        {upload.isPending && (
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
