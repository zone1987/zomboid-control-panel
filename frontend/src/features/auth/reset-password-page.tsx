import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { toast } from 'sonner'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { PasswordInput } from '@/components/ui/password-input'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { FullPageSpinner } from '@/components/full-page-spinner'
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form'
import { completePasswordReset, inspectResetToken } from './password-reset'

const schema = z.object({
  password: z.string().min(12, 'validation.passwordTooShort').max(4096),
})

type FormValues = z.infer<typeof schema>

export function ResetPasswordPage() {
  const { t } = useTranslation()
  const { token = '' } = useParams()
  const navigate = useNavigate()

  const { data, error, isPending } = useQuery({
    queryKey: ['reset-token', token],
    queryFn: () => inspectResetToken(token),
    retry: false,
  })

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { password: '' },
  })

  const complete = useMutation({
    mutationFn: (values: FormValues) => completePasswordReset(token, values.password),
    onSuccess: () => {
      toast.success(t('auth.passwordChanged'))
      void navigate('/login', { replace: true })
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError && error.status === 404
          ? t('auth.resetLinkInvalid')
          : t('errors.generic'),
      )
    },
  })

  if (isPending) {
    return <FullPageSpinner />
  }

  if (error || !data?.valid) {
    return (
      <div className="flex min-h-svh items-center justify-center p-6">
        <div className="w-full max-w-md space-y-4">
          <Alert variant="destructive">
            <AlertTitle>{t('auth.resetLinkInvalid')}</AlertTitle>
            <AlertDescription>{t('auth.resetLinkInvalidHint')}</AlertDescription>
          </Alert>

          <Button variant="outline" className="w-full" asChild>
            <Link to="/forgot-password">{t('auth.requestNewLink')}</Link>
          </Button>
        </div>
      </div>
    )
  }

  return (
    <div className="flex min-h-svh items-center justify-center p-6">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle>{t('auth.chooseNewPassword')}</CardTitle>
          <CardDescription>{t('auth.chooseNewPasswordHint')}</CardDescription>
        </CardHeader>

        <CardContent>
          <Form {...form}>
            <form
              onSubmit={form.handleSubmit((values) => complete.mutate(values))}
              className="space-y-4"
            >
              <FormField
                control={form.control}
                name="password"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t('auth.newPassword')}</FormLabel>
                    <FormControl>
                      <PasswordInput autoComplete="new-password" autoFocus {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <Button type="submit" className="w-full" disabled={complete.isPending}>
                {complete.isPending ? t('common.loading') : t('common.save')}
              </Button>
            </form>
          </Form>
        </CardContent>
      </Card>
    </div>
  )
}
