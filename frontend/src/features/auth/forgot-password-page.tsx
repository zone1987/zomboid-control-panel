import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { MailCheck } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form'
import { requestPasswordReset } from './password-reset'

const schema = z.object({ email: z.string().email('validation.emailInvalid') })

type FormValues = z.infer<typeof schema>

export function ForgotPasswordPage() {
  const { t } = useTranslation()
  const [submitted, setSubmitted] = useState(false)

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { email: '' },
  })

  const request = useMutation({
    mutationFn: (values: FormValues) => requestPasswordReset(values.email),
    // The server answers the same either way, so the interface must not
    // hint at whether the address exists.
    onSettled: () => setSubmitted(true),
  })

  return (
    <div className="flex min-h-svh items-center justify-center p-6">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle>{t('auth.forgotPasswordTitle')}</CardTitle>
          <CardDescription>
            {submitted ? t('auth.resetRequestedHint') : t('auth.forgotPasswordHint')}
          </CardDescription>
        </CardHeader>

        <CardContent className="space-y-4">
          {submitted ? (
            <div className="flex items-start gap-3 rounded-md border p-3 text-sm">
              <MailCheck className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
              <p>{t('auth.resetRequested')}</p>
            </div>
          ) : (
            <Form {...form}>
              <form
                onSubmit={form.handleSubmit((values) => request.mutate(values))}
                className="space-y-4"
              >
                <FormField
                  control={form.control}
                  name="email"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t('auth.email')}</FormLabel>
                      <FormControl>
                        <Input type="email" autoComplete="username" autoFocus {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <Button type="submit" className="w-full" disabled={request.isPending}>
                  {request.isPending ? t('common.loading') : t('auth.sendResetLink')}
                </Button>
              </form>
            </Form>
          )}

          <Button variant="ghost" className="w-full" asChild>
            <Link to="/login">{t('common.back')}</Link>
          </Button>
        </CardContent>
      </Card>
    </div>
  )
}
