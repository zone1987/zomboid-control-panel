import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Boxes, Check, HelpCircle, Trash2 } from 'lucide-react'

import { errorField } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { DropZone } from '@/components/ui/drop-zone'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { TexturePackInstructions } from './instructions'
import {
  deleteTexturePack,
  textureStatus,
  uploadTexturePack,
  type TexturePack,
} from './texture-packs'

function megabytes(bytes: number): string {
  return `${(bytes / 1024 / 1024).toFixed(bytes < 10 * 1024 * 1024 ? 1 : 0)} MB`
}

export function TexturePacksCard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [progress, setProgress] = useState<{ name: string; sent: number; total: number } | null>(null)

  const { data } = useQuery({ queryKey: ['texture-packs'], queryFn: textureStatus })

  const upload = useMutation({
    mutationFn: async (files: File[]) => {
      const chunkBytes = data?.chunkBytes ?? 8 * 1024 * 1024

      for (const file of files) {
        setProgress({ name: file.name, sent: 0, total: file.size })
        await uploadTexturePack(file, chunkBytes, (sent) =>
          setProgress({ name: file.name, sent, total: file.size }),
        )
      }
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['texture-packs'] })
      toast.success(t('settings.textures.uploaded'))
    },
    onError: (error) => {
      const key = errorField(error, 'error')

      toast.error(
        key === null
          ? t('errors.generic')
          : t(`settings.textures.${key.split('.').pop()}`, t('errors.generic')),
      )
    },
    onSettled: () => setProgress(null),
  })

  const remove = useMutation({
    mutationFn: deleteTexturePack,
    onSuccess: (status) => queryClient.setQueryData(['texture-packs'], status),
  })

  const packs = data?.required ?? []
  const missing = packs.filter((pack) => !pack.present)

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settings.textures.title')}</CardTitle>
        <CardDescription>{t('settings.textures.description')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <Alert>
          <Boxes className="size-4" />
          <AlertTitle>
            {data?.complete === true
              ? t('settings.textures.ready')
              : t('settings.textures.stillMissing', { count: missing.length })}
          </AlertTitle>
          <AlertDescription>{t('settings.textures.whatFor')}</AlertDescription>
        </Alert>

        <Dialog>
          <DialogTrigger asChild>
            <Button type="button" variant="outline" size="sm">
              <HelpCircle className="size-4" />
              {t('settings.textures.where')}
            </Button>
          </DialogTrigger>

          <DialogContent className="sm:max-w-lg">
            <DialogHeader>
              <DialogTitle>{t('settings.textures.where')}</DialogTitle>
              <DialogDescription>{t('settings.textures.whereIntro')}</DialogDescription>
            </DialogHeader>

            <div className="text-sm">
              <TexturePackInstructions />
            </div>
          </DialogContent>
        </Dialog>

        <ul className="divide-y rounded-md border">
          {packs.map((pack) => (
            <PackRow
              key={pack.name}
              pack={pack}
              busy={progress?.name === pack.name}
              progress={progress?.name === pack.name ? progress : null}
              onRemove={() => remove.mutate(pack.name)}
            />
          ))}
        </ul>

        <DropZone accept=".pack" disabled={upload.isPending} onFiles={(files) => upload.mutate(files)}>
          <span className="text-sm font-medium">
            {upload.isPending ? t('settings.textures.uploading') : t('settings.textures.drop')}
          </span>
          <span className="text-xs text-muted-foreground">{t('settings.textures.dropHint')}</span>
        </DropZone>

        {upload.isPending && progress !== null && (
          <p className="text-sm text-muted-foreground">
            {progress.name} — {megabytes(progress.sent)} / {megabytes(progress.total)}
          </p>
        )}

        {/* Several hundred megabytes take minutes on a domestic line,
            and a closed tab loses the pieces still to come. */}
        {upload.isPending && (
          <p className="text-xs text-muted-foreground">{t('settings.textures.patience')}</p>
        )}
      </CardContent>
    </Card>
  )
}

function PackRow({
  pack,
  busy,
  progress,
  onRemove,
}: {
  pack: TexturePack
  busy: boolean
  progress: { sent: number; total: number } | null
  onRemove: () => void
}) {
  const { t } = useTranslation()
  const percent = progress === null ? 0 : Math.round((progress.sent / progress.total) * 100)

  return (
    <li className="flex items-center gap-3 px-3 py-2">
      <span className="flex size-5 shrink-0 items-center justify-center">
        {pack.present ? (
          <Check className="size-4 text-emerald-600 dark:text-emerald-500" />
        ) : (
          <span className="size-2 rounded-full bg-muted-foreground/40" />
        )}
      </span>

      <span className="min-w-0 flex-1">
        <span className="block truncate font-mono text-sm">{pack.name}</span>

        {busy && progress !== null && (
          <span className="mt-1 block h-1 w-full overflow-hidden rounded-full bg-muted">
            <span
              className="block h-full bg-primary transition-[width]"
              style={{ width: `${percent}%` }}
            />
          </span>
        )}
      </span>

      <span className="shrink-0 text-xs text-muted-foreground">
        {pack.present ? megabytes(pack.bytes) : t('settings.textures.missing')}
      </span>

      {pack.present && (
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="size-7 shrink-0"
          aria-label={t('common.delete')}
          onClick={onRemove}
        >
          <Trash2 className="size-4" />
        </Button>
      )}
    </li>
  )
}
