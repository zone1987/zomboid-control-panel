import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Car, XCircle } from 'lucide-react'

import { DropZone } from '@/components/ui/drop-zone'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  uploadVehicleModels,
  vehicleModelStatus,
  type ModelUploadOutcome,
} from './vehicle-models'

/** Refused before it is sent: the endpoint accepts 8 MB a file. */
const MAX_FILE_BYTES = 8 * 1024 * 1024

export function VehicleModelsCard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [progress, setProgress] = useState<{ done: number; total: number } | null>(null)
  const [failures, setFailures] = useState<ModelUploadOutcome[]>([])

  const { data: status } = useQuery({
    queryKey: ['vehicle-models'],
    queryFn: vehicleModelStatus,
  })

  const upload = useMutation({
    mutationFn: (files: File[]) =>
      uploadVehicleModels(files, (done, total) => setProgress({ done, total })),
    onSuccess: (outcomes) => {
      setProgress(null)
      setFailures(outcomes.filter((outcome) => outcome.failed))
      void queryClient.invalidateQueries({ queryKey: ['vehicle-models'] })

      const worked = outcomes.length - outcomes.filter((outcome) => outcome.failed).length

      if (worked === 0) {
        toast.error(t('settings.vehicleModels.noneWorked'))

        return
      }

      toast.success(t('settings.vehicleModels.done', { count: worked }))
    },
    onError: () => {
      setProgress(null)
      toast.error(t('errors.generic'))
    },
  })

  const choose = (files: File[]) => {
    if (files.length === 0) {
      return
    }

    const tooBig = files.filter((file) => file.size > MAX_FILE_BYTES)

    if (tooBig.length > 0) {
      toast.error(t('settings.vehicleModels.tooLarge', { name: tooBig[0].name }))

      return
    }

    setFailures([])
    upload.mutate(files)
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settings.vehicleModels.title')}</CardTitle>
        <CardDescription>{t('settings.vehicleModels.description')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <Alert>
          <Car className="size-4" />
          <AlertTitle>
            {status?.available === true
              ? t('settings.vehicleModels.have', {
                  models: status.models,
                  textures: status.textures,
                })
              : t('settings.vehicleModels.haveNone')}
          </AlertTitle>
          <AlertDescription>
            {status?.available === true ? (
              <p>{t('settings.vehicleModels.ready')}</p>
            ) : (
              <>
                <p>{t('settings.vehicleModels.where')}</p>
                <p className="font-mono text-xs">
                  media/models_X/vehicles/*.fbx · media/textures/Vehicles/*.png
                </p>
              </>
            )}
          </AlertDescription>
        </Alert>

        <DropZone accept=".fbx,.png" disabled={upload.isPending} onFiles={choose}>
          <span className="text-sm font-medium">
            {upload.isPending
              ? t('settings.vehicleModels.working', {
                  done: progress?.done ?? 0,
                  total: progress?.total ?? 0,
                })
              : t('settings.vehicleModels.drop')}
          </span>
          <span className="text-xs text-muted-foreground">
            {t('settings.vehicleModels.dropHint')}
          </span>
        </DropZone>

        {failures.length > 0 && (
          <ul className="space-y-1.5 rounded-md border p-3 text-sm">
            {failures.map((failure) => (
              <li key={failure.name} className="flex items-start gap-2">
                <XCircle className="mt-0.5 size-4 shrink-0 text-destructive" />
                <span className="min-w-0 flex-1">
                  <span className="font-medium">{failure.name}</span>{' '}
                  <span className="text-muted-foreground">
                    {t(failure.error ?? 'errors.generic', { defaultValue: '' })}
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
