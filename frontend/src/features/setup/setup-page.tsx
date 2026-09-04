import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'
import { toast } from 'sonner'

import { apiFetch, ApiError } from '@/lib/api'
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

// Messages are translation keys, resolved by FormMessage below.
const schema = z.object({
  displayName: z
    .string()
    .min(2, 'validation.displayNameTooShort')
    .max(100, 'validation.displayNameTooLong'),
  email: z.string().email('validation.emailInvalid'),
  // Matches the server-side rule so the two cannot drift apart.
  password: z.string().min(12, 'validation.passwordTooShort').max(4096),
})

type FormValues = z.infer<typeof schema>

export function SetupPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { displayName: '', email: '', password: '' },
  })

  const { mutate, isPending } = useMutation({
    mutationFn: (values: FormValues) =>
      apiFetch<{ status: string }>('/setup', { method: 'POST', body: values }),
    onSuccess: async () => {
      // The gate caches the status indefinitely; without this it would keep
      // routing back here after the account exists.
      await queryClient.invalidateQueries({ queryKey: ['setup-status'] })
      toast.success(t('setup.createAccount'))
      void navigate('/login', { replace: true })
    },
    onError: (error) => {
      if (error instanceof ApiError && error.status === 409) {
        void queryClient.invalidateQueries({ queryKey: ['setup-status'] })
        toast.error(t('setup.alreadyCompleted'))
        void navigate('/login', { replace: true })

        return
      }

      toast.error(t('errors.generic'))
    },
  })

  return (
    <div className="flex min-h-svh items-center justify-center p-6">
      <Card className="w-full max-w-md">
        <CardHeader>
          <CardTitle>{t('setup.title')}</CardTitle>
          <CardDescription>{t('setup.description')}</CardDescription>
        </CardHeader>

        <CardContent>
          <Form {...form}>
            <form onSubmit={form.handleSubmit((values) => mutate(values))} className="space-y-4">
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
                name="email"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t('auth.email')}</FormLabel>
                    <FormControl>
                      <Input type="email" autoComplete="username" {...field} />
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

              <Button type="submit" className="w-full" disabled={isPending}>
                {isPending ? t('common.loading') : t('setup.createAccount')}
              </Button>
            </form>
          </Form>
        </CardContent>
      </Card>
    </div>
  )
}
