import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Link2Off } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { GoogleIcon, SteamIcon } from '@/features/auth/provider-icons'
import { useAuth } from '@/features/auth/auth-context'
import { listLinkedAccounts, startLinking, unlinkAccount, type LinkedAccount } from './linked-accounts'

const PROVIDERS = ['google', 'steam'] as const

export function LinkedAccountsCard() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const { refresh } = useAuth()

  const { data, isPending } = useQuery({
    queryKey: ['linked-accounts'],
    queryFn: listLinkedAccounts,
  })

  const unlink = useMutation({
    mutationFn: unlinkAccount,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['linked-accounts'] })
      await refresh()
    },
    onError: (error) => {
      if (error instanceof ApiError && error.status === 409) {
        toast.error(t('profile.lastSignInMethod'))

        return
      }

      toast.error(t('errors.generic'))
    },
  })

  const linkedFor = (provider: string): LinkedAccount | undefined =>
    data?.items.find((item) => item.provider === provider)

  const formatDate = (value: string) =>
    new Intl.DateTimeFormat(i18n.language, { dateStyle: 'medium' }).format(new Date(value))

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('profile.linkedAccounts')}</CardTitle>
        <CardDescription>{t('profile.linkedAccountsHint')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-3">
        {isPending && <Skeleton className="h-24 w-full" />}

        {data && (
          <ul className="divide-y rounded-md border">
            {PROVIDERS.map((provider) => {
              const linked = linkedFor(provider)
              const Icon = provider === 'google' ? GoogleIcon : SteamIcon

              return (
                <li key={provider} className="flex items-center gap-3 p-3">
                  <Icon className="size-5 shrink-0" />

                  <div className="min-w-0 flex-1">
                    <p className="font-medium capitalize">{provider}</p>
                    <p className="truncate text-xs text-muted-foreground">
                      {linked
                        ? `${linked.label ?? t('profile.linkedSince')} · ${formatDate(linked.linkedAt)}`
                        : t('profile.notLinked')}
                    </p>
                  </div>

                  {linked ? (
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={unlink.isPending}
                      onClick={() => unlink.mutate(provider)}
                    >
                      <Link2Off className="size-4" />
                      {t('profile.unlink')}
                    </Button>
                  ) : (
                    <Button variant="outline" size="sm" onClick={() => startLinking(provider)}>
                      {t('profile.link')}
                    </Button>
                  )}
                </li>
              )
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
