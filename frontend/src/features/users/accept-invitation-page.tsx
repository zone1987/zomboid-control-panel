import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { toast } from 'sonner'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
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
import { acceptInvitation, inspectInvitation } from './invitations'

const schema = z.object({
  displayName: z
    .string()
    .min(2, 'validation.displayNameTooShort')
    .max(100, 'validation.displayNameTooLong'),
  password: z.string().min(12, 'validation.passwordTooShort').max(4096),
})

type FormValues = z.infer<typeof schema>

export function AcceptInvitationPage() {
  const { t } = useTranslation()
  const { token = '' } = useParams()
  const navigate = useNavigate()

  const { data, error, isPending } = useQuery({
    queryKey: ['invitation', token],
    queryFn: () => inspectInvitation(token),
    retry: false,
  })

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { displayName: '', password: '' },
  })

  const accept = useMutation({
    mutationFn: (values: FormValues) =>
      acceptInvitation(token, values.displayName, values.password),
    onSuccess: () => {
      toast.success(t('users.accountCreated'))
      void navigate('/login', { replace: true })
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError && error.status === 404
          ? t('users.linkInvalid')
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
        <Alert variant="destructive" className="max-w-md">
          <AlertTitle>{t('users.linkInvalid')}</AlertTitle>
          <AlertDescription>{t('users.linkInvalidHint')}</AlertDescription>
        </Alert>
      </div>
    )
  }

  return (
    <div className="flex min-h-svh items-center justify-center p-6">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle>{t('users.acceptTitle')}</CardTitle>
          <CardDescription>{t('users.acceptHint', { email: data.email })}</CardDescription>
        </CardHeader>

        <CardContent>
          <Form {...form}>
            <form onSubmit={form.handleSubmit((values) => accept.mutate(values))} className="space-y-4">
              <FormField
                control={form.control}
                name="displayName"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t('setup.displayName')}</FormLabel>
                    <FormControl>
                      <Input autoComplete="name" autoFocus {...field} />
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
                      <Input type="password" autoComplete="new-password" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <Button type="submit" className="w-full" disabled={accept.isPending}>
                {accept.isPending ? t('common.loading') : t('users.createAccount')}
              </Button>
            </form>
          </Form>
        </CardContent>
      </Card>
    </div>
  )
}
