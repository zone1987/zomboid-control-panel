import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { toast } from 'sonner'
import { KeyRound } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Separator } from '@/components/ui/separator'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form'
import { useAuth } from './auth-context'
import { browserSupportsWebAuthn, isUserCancellation, signInWithPasskey } from './passkeys'
import { TwoFactorPrompt } from './two-factor-prompt'
import { GoogleIcon, SteamIcon } from './provider-icons'
import { useRedirectNotice } from './use-redirect-notice'

const schema = z.object({
  email: z.string().email('validation.emailInvalid'),
  password: z.string().min(1, 'validation.passwordRequired'),
})

type FormValues = z.infer<typeof schema>

export function LoginPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { signIn, refresh } = useAuth()
  const [twoFactorPending, setTwoFactorPending] = useState(false)
  const [passkeysSupported, setPasskeysSupported] = useState(false)

  useEffect(() => {
    setPasskeysSupported(browserSupportsWebAuthn())
  }, [])

  useRedirectNotice()

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { email: '', password: '' },
  })

  const { mutate, isPending } = useMutation({
    mutationFn: (values: FormValues) => signIn(values.email, values.password),
    onSuccess: (response) => {
      if (response.twoFactorComplete) {
        void navigate('/', { replace: true })

        return
      }

      setTwoFactorPending(true)
    },
    onError: (error) => {
      const key = error instanceof ApiError ? extractErrorKey(error) : 'errors.generic'
      toast.error(t(key))
      form.setValue('password', '')
    },
  })

  const passkeyLogin = useMutation({
    mutationFn: () => signInWithPasskey(form.getValues('email') || undefined),
    onSuccess: async () => {
      await refresh()
      void navigate('/', { replace: true })
    },
    onError: (error) => {
      if (isUserCancellation(error)) {
        return
      }

      toast.error(t('auth.passkeyFailed'))
    },
  })

  if (twoFactorPending) {
    return <TwoFactorPrompt onCancel={() => setTwoFactorPending(false)} />
  }

  return (
    <div className="flex min-h-svh items-center justify-center p-6">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle>{t('auth.signIn')}</CardTitle>
          <CardDescription>{t('common.appName')}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-6">
          <Form {...form}>
            <form onSubmit={form.handleSubmit((values) => mutate(values))} className="space-y-4">
              <FormField
                control={form.control}
                name="email"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t('auth.email')}</FormLabel>
                    <FormControl>
                      <Input type="email" autoComplete="username webauthn" autoFocus {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="password"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t('auth.password')}</FormLabel>
                    <FormControl>
                      <Input type="password" autoComplete="current-password" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <Button type="submit" className="w-full" disabled={isPending}>
                {isPending ? t('common.loading') : t('auth.signIn')}
              </Button>
            </form>
          </Form>

          <div className="flex items-center gap-3">
            <Separator className="flex-1" />
            <span className="text-xs text-muted-foreground">{t('auth.orContinueWith')}</span>
            <Separator className="flex-1" />
          </div>

          <div className="grid gap-2">
            <Button
              variant="outline"
              className="w-full"
              disabled={!passkeysSupported || passkeyLogin.isPending}
              onClick={() => passkeyLogin.mutate()}
            >
              <KeyRound className="size-4" />
              {passkeyLogin.isPending ? t('common.loading') : t('auth.signInWithPasskey')}
            </Button>

            <Button variant="outline" className="w-full" asChild>
              <a href="/api/connect/google">
                <GoogleIcon className="size-4" />
                {t('auth.signInWithGoogle')}
              </a>
            </Button>

            <Button variant="outline" className="w-full" asChild>
              <a href="/api/connect/steam">
                <SteamIcon className="size-4" />
                {t('auth.signInWithSteam')}
              </a>
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

function extractErrorKey(error: ApiError): string {
  const payload = error.payload

  if (typeof payload === 'object' && payload !== null && 'error' in payload) {
    const key = (payload as { error: unknown }).error

    if (typeof key === 'string' && key.includes('.')) {
      return key
    }
  }

  return 'errors.generic'
}
