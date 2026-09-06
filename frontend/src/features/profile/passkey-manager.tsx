import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { KeyRound, Pencil, Trash2 } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  browserSupportsWebAuthn,
  deletePasskey,
  isUserCancellation,
  listPasskeys,
  registerPasskey,
  renamePasskey,
  type Passkey,
} from '@/features/auth/passkeys'

export function PasskeyManager() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  // A fact about the browser, read once, rather than state set in an
  // effect — see login-page.tsx for the same reasoning.
  const [supported] = useState(browserSupportsWebAuthn)
  const [pendingRename, setPendingRename] = useState<Passkey | null>(null)

  const { data, isPending } = useQuery({
    queryKey: ['passkeys'],
    queryFn: listPasskeys,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['passkeys'] })

  const register = useMutation({
    mutationFn: () => registerPasskey(suggestDeviceName()),
    onSuccess: async (passkey) => {
      await invalidate()
      toast.success(passkey.name)
    },
    onError: (error) => {
      if (isUserCancellation(error)) {
        return
      }

      toast.error(t('profile.passkeyRegistrationFailed'))
    },
  })

  const rename = useMutation({
    mutationFn: ({ id, name }: { id: string; name: string }) => renamePasskey(id, name),
    onSuccess: async () => {
      await invalidate()
      setPendingRename(null)
    },
    onError: () => toast.error(t('errors.generic')),
  })

  const remove = useMutation({
    mutationFn: (id: string) => deletePasskey(id),
    onSuccess: invalidate,
    onError: (error) => {
      if (error instanceof ApiError && error.status === 409) {
        toast.error(t('profile.passkeyLastOne'))

        return
      }

      toast.error(t('errors.generic'))
    },
  })

  const formatDate = (value: string | null) =>
    value === null
      ? t('profile.never')
      : new Intl.DateTimeFormat(i18n.language, { dateStyle: 'medium', timeStyle: 'short' }).format(
          new Date(value),
        )

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('profile.passkeys')}</CardTitle>
        <CardDescription>{t('profile.passkeysHint')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        {isPending && <Skeleton className="h-24 w-full" />}

        {data?.items.length === 0 && (
          <Empty>
            <EmptyHeader>
              <EmptyMedia variant="icon">
                <KeyRound />
              </EmptyMedia>
              <EmptyTitle>{t('profile.noPasskeys')}</EmptyTitle>
              <EmptyDescription>{t('profile.passkeysHint')}</EmptyDescription>
            </EmptyHeader>
          </Empty>
        )}

        {data && data.items.length > 0 && (
          <ul className="divide-y rounded-md border">
            {data.items.map((passkey) => (
              <li key={passkey.id} className="flex items-center gap-3 p-3">
                <KeyRound className="size-4 shrink-0 text-muted-foreground" />

                <div className="min-w-0 flex-1">
                  <p className="truncate font-medium">{passkey.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {t('profile.lastUsed')}: {formatDate(passkey.lastUsedAt)}
                  </p>
                </div>

                <Button
                  variant="ghost"
                  size="icon"
                  aria-label={t('common.edit')}
                  onClick={() => setPendingRename(passkey)}
                >
                  <Pencil className="size-4" />
                </Button>

                <Button
                  variant="ghost"
                  size="icon"
                  aria-label={t('common.delete')}
                  disabled={remove.isPending}
                  onClick={() => remove.mutate(passkey.id)}
                >
                  <Trash2 className="size-4" />
                </Button>
              </li>
            ))}
          </ul>
        )}

        <Button disabled={!supported || register.isPending} onClick={() => register.mutate()}>
          <KeyRound className="size-4" />
          {register.isPending ? t('common.loading') : t('profile.addPasskey')}
        </Button>

        {!supported && (
          <p className="text-sm text-muted-foreground">{t('profile.passkeysUnsupported')}</p>
        )}
      </CardContent>

      <RenameDialog
        passkey={pendingRename}
        onClose={() => setPendingRename(null)}
        onSubmit={(name) => pendingRename && rename.mutate({ id: pendingRename.id, name })}
        isPending={rename.isPending}
      />
    </Card>
  )
}

function RenameDialog({
  passkey,
  onClose,
  onSubmit,
  isPending,
}: {
  passkey: Passkey | null
  onClose: () => void
  onSubmit: (name: string) => void
  isPending: boolean
}) {
  const { t } = useTranslation()
  const [name, setName] = useState('')

  useEffect(() => {
    setName(passkey?.name ?? '')
  }, [passkey])

  return (
    <Dialog open={passkey !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('common.edit')}</DialogTitle>
          <DialogDescription>{t('profile.passkeyName')}</DialogDescription>
        </DialogHeader>

        <div className="space-y-2">
          <Label htmlFor="passkey-name">{t('profile.passkeyName')}</Label>
          <Input
            id="passkey-name"
            value={name}
            autoFocus
            onChange={(event) => setName(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter' && name.trim() !== '') {
                onSubmit(name.trim())
              }
            }}
          />
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button disabled={isPending || name.trim() === ''} onClick={() => onSubmit(name.trim())}>
            {t('common.save')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/** A sensible default the user can correct afterwards. */
function suggestDeviceName(): string {
  const agent = navigator.userAgent

  if (/iPhone|iPad/.test(agent)) return 'iPhone'
  if (/Macintosh/.test(agent)) return 'Mac'
  if (/Windows/.test(agent)) return 'Windows'
  if (/Android/.test(agent)) return 'Android'

  return 'Passkey'
}
