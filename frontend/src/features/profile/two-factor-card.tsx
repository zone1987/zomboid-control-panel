import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ShieldCheck, ShieldOff } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { PasswordInput } from '@/components/ui/password-input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useAuth } from '@/features/auth/auth-context'
import { TwoFactorSetupDialog } from './two-factor-setup-dialog'
import { BackupCodeList } from './backup-code-list'
import { disableTwoFactor, getTwoFactorStatus, regenerateBackupCodes } from './two-factor'

type PasswordPrompt = 'disable' | 'regenerate' | null

export function TwoFactorCard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { refresh } = useAuth()
  const [setupOpen, setSetupOpen] = useState(false)
  const [prompt, setPrompt] = useState<PasswordPrompt>(null)
  const [freshCodes, setFreshCodes] = useState<string[] | null>(null)

  const { data, isPending } = useQuery({
    queryKey: ['two-factor-status'],
    queryFn: getTwoFactorStatus,
  })

  const afterChange = async () => {
    await queryClient.invalidateQueries({ queryKey: ['two-factor-status'] })
    await refresh()
  }

  const disable = useMutation({
    mutationFn: disableTwoFactor,
    onSuccess: async () => {
      setPrompt(null)
      await afterChange()
      toast.success(t('profile.twoFactorDisabled'))
    },
    onError: (error) => toast.error(passwordErrorKey(error, t)),
  })

  const regenerate = useMutation({
    mutationFn: regenerateBackupCodes,
    onSuccess: async (result) => {
      setPrompt(null)
      setFreshCodes(result.backupCodes)
      await afterChange()
    },
    onError: (error) => toast.error(passwordErrorKey(error, t)),
  })

  return (
    <Card>
      <CardHeader>
        <div className="flex items-start justify-between gap-3">
          <div>
            <CardTitle>{t('profile.twoFactor')}</CardTitle>
            <CardDescription>{t('profile.twoFactorHint')}</CardDescription>
          </div>

          {data && (
            <Badge variant={data.enabled ? 'default' : 'secondary'}>
              {data.enabled ? t('profile.twoFactorOn') : t('profile.twoFactorOff')}
            </Badge>
          )}
        </div>
      </CardHeader>

      <CardContent className="space-y-4">
        {isPending && <Skeleton className="h-10 w-full" />}

        {data && !data.enabled && (
          <Button onClick={() => setSetupOpen(true)}>
            <ShieldCheck className="size-4" />
            {t('profile.twoFactorEnable')}
          </Button>
        )}

        {data?.enabled && (
          <>
            <p className="text-sm text-muted-foreground">
              {t('profile.backupCodesRemaining', { count: data.backupCodesRemaining })}
            </p>

            <div className="flex flex-wrap gap-2">
              <Button variant="outline" onClick={() => setPrompt('regenerate')}>
                {t('profile.regenerateBackupCodes')}
              </Button>

              <Button variant="outline" onClick={() => setPrompt('disable')}>
                <ShieldOff className="size-4" />
                {t('profile.twoFactorDisable')}
              </Button>
            </div>
          </>
        )}
      </CardContent>

      <TwoFactorSetupDialog
        open={setupOpen}
        onClose={() => setSetupOpen(false)}
        onEnabled={() => void afterChange()}
      />

      <PasswordPromptDialog
        mode={prompt}
        isPending={disable.isPending || regenerate.isPending}
        onClose={() => setPrompt(null)}
        onConfirm={(password) =>
          prompt === 'disable' ? disable.mutate(password) : regenerate.mutate(password)
        }
      />

      <Dialog open={freshCodes !== null} onOpenChange={(open) => !open && setFreshCodes(null)}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t('profile.backupCodesTitle')}</DialogTitle>
            <DialogDescription>{t('profile.backupCodesShownOnce')}</DialogDescription>
          </DialogHeader>

          {freshCodes && <BackupCodeList codes={freshCodes} />}

          <DialogFooter>
            <Button onClick={() => setFreshCodes(null)}>{t('common.close')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  )
}

function PasswordPromptDialog({
  mode,
  isPending,
  onClose,
  onConfirm,
}: {
  mode: PasswordPrompt
  isPending: boolean
  onClose: () => void
  onConfirm: (password: string) => void
}) {
  const { t } = useTranslation()
  const [password, setPassword] = useState('')

  const close = () => {
    setPassword('')
    onClose()
  }

  return (
    <Dialog open={mode !== null} onOpenChange={(open) => !open && close()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {mode === 'disable' ? t('profile.twoFactorDisable') : t('profile.regenerateBackupCodes')}
          </DialogTitle>
          <DialogDescription>{t('profile.confirmWithPassword')}</DialogDescription>
        </DialogHeader>

        <div className="space-y-2">
          <Label htmlFor="confirm-password">{t('auth.password')}</Label>
          <PasswordInput
            id="confirm-password"
            autoComplete="current-password"
            autoFocus
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter' && password !== '') {
                onConfirm(password)
              }
            }}
          />
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={close}>
            {t('common.cancel')}
          </Button>
          <Button
            variant={mode === 'disable' ? 'destructive' : 'default'}
            disabled={password === '' || isPending}
            onClick={() => onConfirm(password)}
          >
            {isPending ? t('common.loading') : t('common.continue')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function passwordErrorKey(error: unknown, t: (key: string) => string): string {
  return error instanceof ApiError && error.status === 403
    ? t('auth.invalidCredentials')
    : t('errors.generic')
}
