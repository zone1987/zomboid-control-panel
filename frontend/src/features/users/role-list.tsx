import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { AlertTriangle, Lock, Plus, Save, Shield, Trash2 } from 'lucide-react'

import { cn } from '@/lib/utils'
import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { Skeleton } from '@/components/ui/skeleton'
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
import {
  createRole,
  deleteRole,
  listRoles,
  sortGroups,
  updateRole,
  type PanelRole,
} from './roles'

export function RoleList() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [chosen, setChosen] = useState<string | null>(null)
  // One draft rather than two states plus an effect to seed them: it
  // remembers which role it belongs to, so switching role shows that
  // role and a refetch cannot overwrite an unsaved edit.
  const [edit, setEdit] = useState<{
    from: string | undefined
    label: string
    permissions: string[]
  } | null>(null)
  const [removing, setRemoving] = useState<PanelRole | null>(null)

  const { data, isPending } = useQuery({ queryKey: ['roles'], queryFn: listRoles })

  const roles = data?.items ?? []

  // Derived during render rather than chosen in an effect: with nothing
  // picked the first role is the one being looked at, and an effect
  // setting that would render once with no selection and again with one.
  const role = roles.find((entry) => entry.id === chosen) ?? roles[0] ?? null

  // The edit is held against the role it was started from, so switching
  // role shows that role's own values and a poll cannot overwrite an
  // unsaved change. An effect copying the role into state did both
  // wrongly: it clobbered the draft whenever the list refetched.
  const saved = { label: role?.label ?? '', permissions: role?.permissions ?? [] }
  const draft = edit !== null && edit.from === role?.id ? edit : { ...saved, from: role?.id }

  const label = draft.label
  const ticked = draft.permissions

  const change = (next: { label?: string; permissions?: string[] }) =>
    setEdit({
      from: role?.id,
      label: next.label ?? label,
      permissions: next.permissions ?? ticked,
    })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['roles'] })

  const save = useMutation({
    mutationFn: () => updateRole(role?.id ?? '', { label, permissions: ticked }),
    onSuccess: () => {
      // The draft is spent: the next read is what the role is now.
      setEdit(null)
      void refresh()
      toast.success(t('roles.saved'))
    },
    onError: () => toast.error(t('errors.generic')),
  })

  const add = useMutation({
    mutationFn: () => createRole(t('roles.newName'), []),
    onSuccess: (created) => {
      void refresh()
      setChosen(created.id)
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError && error.status === 422
          ? t('roles.nameTaken')
          : t('errors.generic'),
      ),
  })

  const remove = useMutation({
    mutationFn: (id: string) => deleteRole(id),
    onSuccess: () => {
      void refresh()
      setChosen(null)
      toast.success(t('roles.deleted'))
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError && error.status === 409
          ? t('roles.builtInCannotBeDeleted')
          : t('errors.generic'),
      ),
  })

  const toggle = (name: string) =>
    change({
      permissions: ticked.includes(name)
        ? ticked.filter((entry) => entry !== name)
        : [...ticked, name],
    })

  const changed =
    role !== null &&
    (label !== role.label ||
      ticked.length !== role.permissions.length ||
      ticked.some((name) => !role.permissions.includes(name)))

  if (isPending) {
    return <Skeleton className="h-96 w-full" />
  }

  return (
    <div className="grid gap-4 lg:grid-cols-[16rem_1fr]">
      <div className="space-y-2">
        <div className="space-y-1 rounded-md border p-2">
          {roles.map((entry) => (
            <button
              key={entry.id}
              type="button"
              className={cn(
                'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm',
                entry.id === chosen
                  ? 'bg-primary/10 font-medium'
                  : 'hover:bg-accent hover:text-accent-foreground',
              )}
              onClick={() => setChosen(entry.id)}
            >
              <Shield className="size-3.5 shrink-0 text-muted-foreground" />
              <span className="min-w-0 flex-1 truncate">{entry.label}</span>

              {entry.builtIn && <Lock className="size-3 shrink-0 text-muted-foreground" />}
            </button>
          ))}
        </div>

        <Button variant="outline" className="w-full" disabled={add.isPending} onClick={() => add.mutate()}>
          <Plus className="size-4" />
          {t('roles.add')}
        </Button>
      </div>

      {role === null ? (
        <div className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">
          {t('roles.chooseRole')}
        </div>
      ) : (
        <section className="space-y-4 rounded-md border p-4">
          <div className="flex flex-wrap items-end gap-3">
            <div className="min-w-56 flex-1 space-y-1.5">
              <Label htmlFor="role-label">{t('roles.label')}</Label>
              <Input
                id="role-label"
                value={label}
                onChange={(event) => change({ label: event.target.value })}
              />
            </div>

            {role.builtIn && (
              <Badge variant="secondary" className="mb-2">
                <Lock className="size-3" />
                {t('roles.builtIn')}
              </Badge>
            )}
          </div>

          {role.builtIn && (
            <p className="flex items-start gap-2 rounded-md bg-muted p-3 text-xs text-muted-foreground">
              <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
              {t('roles.builtInHint')}
            </p>
          )}

          <div className="space-y-4">
            {sortGroups(data?.permissions ?? {}).map(([group, entries]) => (
              <div key={group} className="space-y-2">
                <h3 className="text-sm font-medium">
                  {t(`roles.groups.${group}`, { defaultValue: group })}
                </h3>

                <div className="grid gap-2 sm:grid-cols-2">
                  {entries.map((entry) => (
                    <label
                      key={entry.name}
                      className="flex items-start gap-2 rounded-md border p-2 text-sm"
                    >
                      <Checkbox
                        className="mt-0.5"
                        checked={ticked.includes(entry.name)}
                        onCheckedChange={() => toggle(entry.name)}
                      />

                      <span className="min-w-0 flex-1">
                        <span className="block">
                          {t(`roles.permissions.${entry.name}.title`, { defaultValue: entry.name })}
                        </span>
                        <span className="block text-xs text-muted-foreground">
                          {t(`roles.permissions.${entry.name}.description`, { defaultValue: '' })}
                        </span>
                      </span>

                      {entry.sensitive && (
                        <Badge variant="outline" className="shrink-0 text-[10px]">
                          {t('roles.sensitive')}
                        </Badge>
                      )}
                    </label>
                  ))}
                </div>
              </div>
            ))}
          </div>

          <div className="flex flex-wrap gap-2 border-t pt-4">
            <Button disabled={!changed || save.isPending} onClick={() => save.mutate()}>
              <Save className="size-4" />
              {save.isPending ? t('common.loading') : t('common.save')}
            </Button>

            {!role.builtIn && (
              <Button variant="ghost" onClick={() => setRemoving(role)}>
                <Trash2 className="size-4" />
                {t('common.delete')}
              </Button>
            )}
          </div>
        </section>
      )}

      <AlertDialog open={removing !== null} onOpenChange={(open) => !open && setRemoving(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t('roles.deleteTitle', { label: removing?.label })}</AlertDialogTitle>
            <AlertDialogDescription>{t('roles.deleteBody')}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t('common.cancel')}</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                if (removing !== null) {
                  remove.mutate(removing.id)
                }

                setRemoving(null)
              }}
            >
              {t('common.delete')}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
