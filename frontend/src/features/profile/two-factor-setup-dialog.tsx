import { useEffect, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import QRCode from 'qrcode'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { InputOTP, InputOTPGroup, InputOTPSeparator, InputOTPSlot } from '@/components/ui/input-otp'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { activateTwoFactor, formatSecret, startTwoFactorSetup } from './two-factor'
import { BackupCodeList } from './backup-code-list'

type Stage = 'scan' | 'codes'

export function TwoFactorSetupDialog({
  open,
  onClose,
  onEnabled,
}: {
  open: boolean
  onClose: () => void
  onEnabled: () => void
}) {
  const { t } = useTranslation()
  const [stage, setStage] = useState<Stage>('scan')
  const [code, setCode] = useState('')
  const [qrDataUrl, setQrDataUrl] = useState<string | null>(null)
  const [backupCodes, setBackupCodes] = useState<string[]>([])

  const setup = useMutation({
    mutationFn: startTwoFactorSetup,
    onError: () => toast.error(t('errors.generic')),
  })

  const activate = useMutation({
    mutationFn: activateTwoFactor,
    onSuccess: (result) => {
      setBackupCodes(result.backupCodes)
      setStage('codes')
      onEnabled()
    },
    onError: (error) => {
      setCode('')

      if (error instanceof ApiError && error.status === 422) {
        toast.error(t('auth.invalidCode'))

        return
      }

      toast.error(t('errors.generic'))
    },
  })

  useEffect(() => {
    if (!open) {
      setStage('scan')
      setCode('')
      setQrDataUrl(null)
      setBackupCodes([])

      return
    }

    setup.mutate(undefined, {
      onSuccess: async (data) => {
        setQrDataUrl(
          await QRCode.toDataURL(data.qrContent, { width: 220, margin: 1 }).catch(() => ''),
        )
      },
    })
    // Running this once per opening is the intent; setup is stable enough.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const finish = () => {
    setStage('scan')
    onClose()
  }

  return (
    <Dialog open={open} onOpenChange={(next) => !next && (stage === 'codes' ? finish() : onClose())}>
      <DialogContent className="sm:max-w-md">
        {stage === 'scan' ? (
          <>
            <DialogHeader>
              <DialogTitle>{t('profile.twoFactorSetupTitle')}</DialogTitle>
              <DialogDescription>{t('profile.twoFactorSetupHint')}</DialogDescription>
            </DialogHeader>

            <div className="space-y-4">
              <div className="flex justify-center">
                {qrDataUrl ? (
                  <img
                    src={qrDataUrl}
                    alt={t('profile.twoFactorQrAlt')}
                    className="rounded-md border bg-white p-2"
                    width={220}
                    height={220}
                  />
                ) : (
                  <Skeleton className="size-[220px]" />
                )}
              </div>

              {setup.data && (
                <div className="space-y-1 text-center">
                  <p className="text-xs text-muted-foreground">{t('profile.twoFactorManualEntry')}</p>
                  <code className="text-sm font-medium tracking-wider">
                    {formatSecret(setup.data.secret)}
                  </code>
                </div>
              )}

              <div className="flex justify-center">
                <InputOTP
                  maxLength={6}
                  value={code}
                  onChange={setCode}
                  onComplete={(value) => activate.mutate(value)}
                  disabled={activate.isPending || !setup.data}
                >
                  <InputOTPGroup>
                    <InputOTPSlot index={0} />
                    <InputOTPSlot index={1} />
                    <InputOTPSlot index={2} />
                  </InputOTPGroup>
                  <InputOTPSeparator />
                  <InputOTPGroup>
                    <InputOTPSlot index={3} />
                    <InputOTPSlot index={4} />
                    <InputOTPSlot index={5} />
                  </InputOTPGroup>
                </InputOTP>
              </div>
            </div>

            <DialogFooter>
              <Button variant="outline" onClick={onClose}>
                {t('common.cancel')}
              </Button>
              <Button
                disabled={code.length !== 6 || activate.isPending}
                onClick={() => activate.mutate(code)}
              >
                {activate.isPending ? t('common.loading') : t('common.continue')}
              </Button>
            </DialogFooter>
          </>
        ) : (
          <>
            <DialogHeader>
              <DialogTitle>{t('profile.backupCodesTitle')}</DialogTitle>
              <DialogDescription>{t('profile.backupCodesHint')}</DialogDescription>
            </DialogHeader>

            <Alert>
              <AlertTitle>{t('profile.backupCodesShownOnce')}</AlertTitle>
              <AlertDescription>{t('profile.backupCodesWarning')}</AlertDescription>
            </Alert>

            <BackupCodeList codes={backupCodes} />

            <DialogFooter>
              <Button onClick={finish}>{t('common.close')}</Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
