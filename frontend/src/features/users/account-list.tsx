import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { KeyRound, MoreHorizontal, Pencil, ShieldCheck, Smartphone, Trash2 } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { AccountDialog } from './account-dialog'
import { deleteAccount, listAccounts, updateAccount, type Account } from './accounts'

export function AccountList() {
  const { t, i18n } = useTranslation()
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<Account | null>(null)
  const [removing, setRemoving] = useState<Account | null>(null)

  const { data, isPending } = useQuery({ queryKey: ['accounts'], queryFn: listAccounts })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['accounts'] })

  const toggleActive = useMutation({
    mutationFn: (account: Account) => updateAccount(account.id, { active: !account.active }),
    onSuccess: async (account) => {
      await invalidate()
      toast.success(account.active ? t('users.accountActivated') : t('users.accountDeactivated'))
    },
    onError: reportConflict(t),
  })

  const remove = useMutation({
    mutationFn: (account: Account) => deleteAccount(account.id),
    onSuccess: async () => {
      await invalidate()
      setRemoving(null)
      toast.success(t('users.accountDeleted'))
    },
    onError: reportConflict(t),
  })

  if (isPending) {
    return <Skeleton className="h-64 w-full" />
  }

  const accounts = data?.items ?? []
  const formatDate = (value: string | null) =>
    value === null ? t('common.never') : new Date(value).toLocaleString(i18n.language)

  const Identity = ({ account }: { account: Account }) => (
    <div className="flex min-w-0 flex-col">
      <span className="flex flex-wrap items-center gap-2 font-medium">
        <span className="break-all">{account.displayName}</span>
        {account.self && (
          <Badge variant="outline" className="text-xs">
            {t('users.you')}
          </Badge>
        )}
        {!account.active && (
          <Badge variant="warning" className="text-xs">
            {t('users.deactivated')}
          </Badge>
        )}
      </span>
      <span className="break-all text-sm text-muted-foreground">{account.email}</span>
    </div>
  )

  const Roles = ({ account }: { account: Account }) => (
    <div className="flex flex-wrap gap-1">
      {account.roles.map((role) => (
        <Badge
          key={role}
          variant={role === 'ROLE_ADMIN' ? 'default' : 'secondary'}
          className="text-xs"
        >
          {t(`users.role.${role}`)}
        </Badge>
      ))}
    </div>
  )

  const SignInMethods = ({ account }: { account: Account }) => (
    <div className="flex flex-wrap items-center gap-2 text-muted-foreground">
      {account.hasPassword && <KeyRound className="size-4" aria-label={t('users.hasPassword')} />}

      {account.passkeyCount > 0 && (
        <span className="flex items-center gap-0.5" title={t('users.passkeys')}>
          <Smartphone className="size-4" />
          <span className="text-xs">{account.passkeyCount}</span>
        </span>
      )}

      {account.twoFactorEnabled && (
        <ShieldCheck className="size-4 text-emerald-500" aria-label={t('users.twoFactor')} />
      )}

      {account.identities.map((provider) => (
        <Badge key={provider} variant="outline" className="text-xs capitalize">
          {provider}
        </Badge>
      ))}

      {!account.hasPassword && account.passkeyCount === 0 && account.identities.length === 0 && (
        <span className="text-xs">{t('users.noSignInMethod')}</span>
      )}
    </div>
  )

  const Actions = ({ account }: { account: Account }) => (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" className="size-8">
          <MoreHorizontal className="size-4" />
          <span className="sr-only">{t('common.actions')}</span>
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end">
        <DropdownMenuItem onSelect={() => setEditing(account)}>
          <Pencil className="size-4" />
          {t('common.edit')}
        </DropdownMenuItem>

        <DropdownMenuItem disabled={account.self} onSelect={() => toggleActive.mutate(account)}>
          {account.active ? t('users.deactivate') : t('users.activate')}
        </DropdownMenuItem>

        <DropdownMenuSeparator />

        <DropdownMenuItem
          variant="destructive"
          disabled={account.self}
          onSelect={() => setRemoving(account)}
        >
          <Trash2 className="size-4" />
          {t('common.delete')}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )

  return (
    <>
      {/* The five columns want 763px, and with the sidebar open a tablet
          leaves 549px, so the switch is at `lg` rather than `sm`. */}
      <ul className="flex flex-col gap-2 lg:hidden">
        {accounts.map((account) => (
          <li
            key={account.id}
            className={cn('flex flex-col gap-2 rounded-md border p-3', !account.active && 'opacity-60')}
          >
            <div className="flex items-start justify-between gap-2">
              <Identity account={account} />
              <Actions account={account} />
            </div>

            <Roles account={account} />
            <SignInMethods account={account} />

            <p className="text-xs text-muted-foreground">
              {t('users.lastLogin')}: {formatDate(account.lastLoginAt)}
            </p>
          </li>
        ))}
      </ul>

      <div className="hidden overflow-x-auto rounded-md border lg:block">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t('users.account')}</TableHead>
              <TableHead>{t('users.roles')}</TableHead>
              <TableHead>{t('users.signInMethods')}</TableHead>
              <TableHead>{t('users.lastLogin')}</TableHead>
              <TableHead className="w-12 text-right">{t('common.actions')}</TableHead>
            </TableRow>
          </TableHeader>

          <TableBody>
            {accounts.map((account) => (
              <TableRow key={account.id} className={account.active ? undefined : 'opacity-60'}>
                <TableCell>
                  <Identity account={account} />
                </TableCell>

                <TableCell>
                  <Roles account={account} />
                </TableCell>

                <TableCell>
                  <SignInMethods account={account} />
                </TableCell>

                <TableCell className="text-sm text-muted-foreground">
                  {formatDate(account.lastLoginAt)}
                </TableCell>

                <TableCell className="text-right">
                  <Actions account={account} />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      {editing && (
        <AccountDialog
          account={editing}
          panelRoles={data?.roles ?? []}
          open
          onOpenChange={(open) => !open && setEditing(null)}
          onSaved={async () => {
            await invalidate()
            setEditing(null)
          }}
        />
      )}

      <AlertDialog open={removing !== null} onOpenChange={(open) => !open && setRemoving(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t('users.deleteTitle')}</AlertDialogTitle>
            <AlertDialogDescription>
              {t('users.deleteDescription', { name: removing?.displayName ?? '' })}
            </AlertDialogDescription>
          </AlertDialogHeader>

          <AlertDialogFooter>
            <AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
            <AlertDialogAction
              onClick={(event) => {
                event.preventDefault()

                if (removing) {
                  remove.mutate(removing)
                }
              }}
            >
              {t('common.delete')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}

/**
 * The backend refuses changes that would lock everyone out; its message
 * key names which rule stopped it, so it is shown rather than a generic
 * failure.
 */
function reportConflict(t: (key: string) => string) {
  return (error: unknown) => {
    if (error instanceof ApiError && error.status === 409) {
      const key =
        typeof error.payload === 'object' && error.payload !== null
          ? ((error.payload as { error?: string }).error ?? '')
          : ''

      toast.error(key === '' ? t('errors.generic') : t(key))

      return
    }

    toast.error(t('errors.generic'))
  }
}
