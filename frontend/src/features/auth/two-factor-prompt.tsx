import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { InputOTP, InputOTPGroup, InputOTPSeparator, InputOTPSlot } from '@/components/ui/input-otp'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useAuth } from './auth-context'

export function TwoFactorPrompt({ onCancel }: { onCancel: () => void }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { submitTwoFactorCode } = useAuth()
  const [code, setCode] = useState('')
  const [usingBackupCode, setUsingBackupCode] = useState(false)

  const { mutate, isPending } = useMutation({
    mutationFn: (value: string) => submitTwoFactorCode(value),
    onSuccess: () => void navigate('/', { replace: true }),
    onError: () => {
      toast.error(t('auth.invalidCode'))
      setCode('')
    },
  })

  const submit = (value: string) => {
    if (!isPending && value.length > 0) {
      mutate(value)
    }
  }

  return (
    <div className="flex min-h-svh items-center justify-center p-6">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle>{t('auth.twoFactorTitle')}</CardTitle>
          <CardDescription>{t('auth.twoFactorHint')}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-6">
          {usingBackupCode ? (
            <div className="space-y-2">
              <Label htmlFor="backup-code">{t('auth.useBackupCode')}</Label>
              <Input
                id="backup-code"
                autoFocus
                autoComplete="one-time-code"
                value={code}
                onChange={(event) => setCode(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') {
                    submit(code)
                  }
                }}
              />
            </div>
          ) : (
            <div className="flex justify-center">
              <InputOTP
                maxLength={6}
                autoFocus
                value={code}
                onChange={setCode}
                // Submitting on completion saves a click on the common path.
                onComplete={submit}
                disabled={isPending}
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
          )}

          <div className="space-y-2">
            <Button className="w-full" disabled={isPending || code.length === 0} onClick={() => submit(code)}>
              {isPending ? t('common.loading') : t('common.continue')}
            </Button>

            <Button
              variant="ghost"
              className="w-full"
              onClick={() => {
                setUsingBackupCode((previous) => !previous)
                setCode('')
              }}
            >
              {usingBackupCode ? t('auth.twoFactorTitle') : t('auth.useBackupCode')}
            </Button>

            <Button variant="ghost" className="w-full" onClick={onCancel}>
              {t('common.back')}
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
