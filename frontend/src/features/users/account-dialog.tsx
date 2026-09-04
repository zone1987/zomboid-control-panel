import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { PasswordInput } from '@/components/ui/password-input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { ASSIGNABLE_ROLES, type AssignableRole } from './invitations'
import { updateAccount, type Account, type AccountDraft } from './accounts'

export function AccountDialog({
  account,
  open,
  onOpenChange,
  onSaved,
}: {
  account: Account
  open: boolean
  onOpenChange: (open: boolean) => void
  onSaved: () => void | Promise<void>
}) {
  const { t } = useTranslation()
  const [displayName, setDisplayName] = useState(account.displayName)
  const [email, setEmail] = useState(account.email)
  const [roles, setRoles] = useState<AssignableRole[]>(account.roles)
  const [password, setPassword] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})

  const save = useMutation({
    mutationFn: () => {
      const values: AccountDraft = { displayName, email, roles }

      if (password !== '') {
        values.password = password
      }

      return updateAccount(account.id, values)
    },
    onSuccess: async () => {
      setErrors({})
      toast.success(t('users.accountSaved'))
      await onSaved()
    },
    onError: (error) => {
      if (error instanceof ApiError && typeof error.payload === 'object' && error.payload !== null) {
        const payload = error.payload as { errors?: Record<string, string>; error?: string }

        if (error.status === 422 && payload.errors) {
          setErrors(payload.errors)

          return
        }

        if (error.status === 409 && payload.error) {
          toast.error(t(payload.error))

          return
        }
      }

      toast.error(t('errors.generic'))
    },
  })

  const toggleRole = (role: AssignableRole, checked: boolean) =>
    setRoles((previous) =>
      checked ? [...new Set([...previous, role])] : previous.filter((r) => r !== role),
    )

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('users.editTitle')}</DialogTitle>
          <DialogDescription>{t('users.editDescription')}</DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="account-name">{t('users.displayName')}</Label>
            <Input
              id="account-name"
              value={displayName}
              onChange={(event) => setDisplayName(event.target.value)}
            />
            {errors.displayName && (
              <p className="text-sm text-destructive">{t(errors.displayName)}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="account-email">{t('users.email')}</Label>
            <Input
              id="account-email"
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
            {errors.email && <p className="text-sm text-destructive">{t(errors.email)}</p>}
          </div>

          <div className="space-y-2">
            <Label>{t('users.roles')}</Label>

            <div className="space-y-2">
              {ASSIGNABLE_ROLES.map((role) => (
                <div key={role} className="flex items-start gap-2">
                  <Checkbox
                    id={`role-${role}`}
                    checked={roles.includes(role)}
                    disabled={account.self && role === 'ROLE_ADMIN'}
                    onCheckedChange={(checked) => toggleRole(role, checked === true)}
                  />
                  <div className="grid gap-0.5 leading-none">
                    <Label htmlFor={`role-${role}`} className="font-normal">
                      {t(`users.role.${role}`)}
                    </Label>
                    <p className="text-xs text-muted-foreground">{t(`users.roleHint.${role}`)}</p>
                  </div>
                </div>
              ))}
            </div>

            {account.self && (
              <p className="text-xs text-muted-foreground">{t('users.cannotDemoteSelfHint')}</p>
            )}
          </div>

          <div className="space-y-2">
            <Label htmlFor="account-password">{t('users.setPassword')}</Label>
            <PasswordInput
              id="account-password"
              value={password}
              autoComplete="new-password"
              placeholder={t('users.setPasswordPlaceholder')}
              onChange={(event) => setPassword(event.target.value)}
            />
            {errors.password && <p className="text-sm text-destructive">{t(errors.password)}</p>}
            <p className="text-xs text-muted-foreground">{t('users.setPasswordHint')}</p>
          </div>
        </div>

        <DialogFooter>
          <Button variant="ghost" onClick={() => onOpenChange(false)}>
            {t('common.cancel')}
          </Button>
          <Button disabled={save.isPending} onClick={() => save.mutate()}>
            {save.isPending ? t('common.loading') : t('common.save')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
