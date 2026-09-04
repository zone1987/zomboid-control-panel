import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { MailPlus, Trash2, UserPlus } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { Skeleton } from '@/components/ui/skeleton'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
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
  ASSIGNABLE_ROLES,
  createInvitation,
  listInvitations,
  revokeInvitation,
  type AssignableRole,
} from './invitations'
import { AccountList } from './account-list'
import { RoleList } from './role-list'

export function UsersPage() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const [inviting, setInviting] = useState(false)
  const [email, setEmail] = useState('')
  const [roles, setRoles] = useState<AssignableRole[]>(['ROLE_USER'])

  const { data, isPending } = useQuery({
    queryKey: ['invitations'],
    queryFn: listInvitations,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['invitations'] })

  const invite = useMutation({
    mutationFn: () => createInvitation(email.trim(), roles),
    onSuccess: async (result) => {
      await invalidate()
      setInviting(false)
      setEmail('')
      setRoles(['ROLE_USER'])
      toast.success(t('users.invitationSent', { email: result.email }))
    },
    onError: (error) => {
      if (error instanceof ApiError && error.status === 409) {
        toast.error(t('users.accountExists'))

        return
      }

      toast.error(
        error instanceof ApiError && error.status === 422
          ? t('validation.emailInvalid')
          : t('errors.generic'),
      )
    },
  })

  const revoke = useMutation({
    mutationFn: revokeInvitation,
    onSuccess: invalidate,
    onError: () => toast.error(t('errors.generic')),
  })

  const formatDate = (value: string) =>
    new Intl.DateTimeFormat(i18n.language, { dateStyle: 'medium', timeStyle: 'short' }).format(
      new Date(value),
    )

  const toggleRole = (role: AssignableRole) =>
    setRoles((previous) =>
      previous.includes(role) ? previous.filter((r) => r !== role) : [...previous, role],
    )

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <div className="flex items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">{t('nav.users')}</h1>
          <p className="text-muted-foreground">{t('users.description')}</p>
        </div>

        <Button onClick={() => setInviting(true)}>
          <UserPlus className="size-4" />
          {t('users.invite')}
        </Button>
      </div>

      <Tabs defaultValue="accounts">
        <TabsList>
          <TabsTrigger value="accounts">{t('users.accountsTab')}</TabsTrigger>
          <TabsTrigger value="invitations">{t('users.invitationsTab')}</TabsTrigger>
          <TabsTrigger value="roles">{t('users.rolesTab')}</TabsTrigger>
        </TabsList>

        <TabsContent value="accounts" className="space-y-4">
          <p className="text-sm text-muted-foreground">{t('users.accountsHint')}</p>
          <AccountList />
        </TabsContent>

        <TabsContent value="invitations">
      <Card>
        <CardHeader>
          <CardTitle>{t('users.invitations')}</CardTitle>
          <CardDescription>{t('users.invitationsHint')}</CardDescription>
        </CardHeader>

        <CardContent>
          {isPending && <Skeleton className="h-24 w-full" />}

          {data?.items.length === 0 && (
            <Empty>
              <EmptyHeader>
                <EmptyMedia variant="icon">
                  <MailPlus />
                </EmptyMedia>
                <EmptyTitle>{t('users.noInvitations')}</EmptyTitle>
                <EmptyDescription>{t('users.invitationsHint')}</EmptyDescription>
              </EmptyHeader>
            </Empty>
          )}

          {data && data.items.length > 0 && (
            <ul className="divide-y rounded-md border">
              {data.items.map((invitation) => (
                <li key={invitation.id} className="flex items-center gap-3 p-3">
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{invitation.email}</p>
                    <p className="text-xs text-muted-foreground">
                      {invitation.acceptedAt
                        ? t('users.acceptedOn', { date: formatDate(invitation.acceptedAt) })
                        : invitation.pending
                          ? t('users.expiresOn', { date: formatDate(invitation.expiresAt) })
                          : t('users.expired')}
                    </p>
                  </div>

                  <div className="flex shrink-0 flex-wrap gap-1">
                    {invitation.roles.map((role) => (
                      <Badge key={role} variant="secondary" className="text-xs">
                        {role.replace('ROLE_', '').toLowerCase()}
                      </Badge>
                    ))}
                  </div>

                  {invitation.acceptedAt === null && (
                    <Button
                      variant="ghost"
                      size="icon"
                      aria-label={t('users.revoke')}
                      disabled={revoke.isPending}
                      onClick={() => revoke.mutate(invitation.id)}
                    >
                      <Trash2 className="size-4" />
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
        </TabsContent>

        <TabsContent value="roles" className="space-y-4">
          <p className="text-sm text-muted-foreground">{t('users.rolesHint')}</p>
          <RoleList />
        </TabsContent>
      </Tabs>

      <Dialog open={inviting} onOpenChange={(open) => !open && setInviting(false)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('users.invite')}</DialogTitle>
            <DialogDescription>{t('users.inviteHint')}</DialogDescription>
          </DialogHeader>

          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="invite-email">{t('auth.email')}</Label>
              <Input
                id="invite-email"
                type="email"
                autoFocus
                value={email}
                onChange={(event) => setEmail(event.target.value)}
              />
            </div>

            <fieldset className="space-y-2">
              <legend className="mb-2 text-sm font-medium">{t('users.roles')}</legend>

              {ASSIGNABLE_ROLES.map((role) => (
                <label key={role} className="flex items-center gap-2 text-sm">
                  <Checkbox
                    checked={roles.includes(role)}
                    onCheckedChange={() => toggleRole(role)}
                  />
                  <span>{t(`users.role.${role}`)}</span>
                </label>
              ))}
            </fieldset>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setInviting(false)}>
              {t('common.cancel')}
            </Button>
            <Button
              disabled={email.trim() === '' || roles.length === 0 || invite.isPending}
              onClick={() => invite.mutate()}
            >
              {invite.isPending ? t('common.loading') : t('users.sendInvitation')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
