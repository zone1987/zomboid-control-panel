import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { CheckCircle2, Plus, Server as ServerIcon } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { Card, CardContent } from '@/components/ui/card'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { createServer, listServers } from './servers'

export function ServerListPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [creating, setCreating] = useState(false)
  const [name, setName] = useState('')

  const { data, isPending } = useQuery({
    queryKey: ['servers'],
    queryFn: listServers,
  })

  const create = useMutation({
    mutationFn: () => createServer(name.trim()),
    onSuccess: async (server) => {
      await queryClient.invalidateQueries({ queryKey: ['servers'] })
      setCreating(false)
      setName('')
      void navigate(`/servers/${server.id}`)
    },
    onError: () => toast.error(t('errors.generic')),
  })

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div className="flex items-center justify-between gap-4">
        <h1 className="text-2xl font-semibold">{t('servers.title')}</h1>

        <Button onClick={() => setCreating(true)}>
          <Plus className="size-4" />
          {t('servers.add')}
        </Button>
      </div>

      {isPending && <Skeleton className="h-32 w-full" />}

      {data?.items.length === 0 && (
        <Empty>
          <EmptyHeader>
            <EmptyMedia variant="icon">
              <ServerIcon />
            </EmptyMedia>
            <EmptyTitle>{t('servers.empty')}</EmptyTitle>
            <EmptyDescription>{t('servers.emptyHint')}</EmptyDescription>
          </EmptyHeader>
        </Empty>
      )}

      {data && data.items.length > 0 && (
        <ul className="space-y-3">
          {data.items.map((server) => (
            <li key={server.id}>
              <Card className="transition-colors hover:border-primary/50">
                <CardContent className="flex items-center gap-4 py-4">
                  <ServerIcon className="size-5 shrink-0 text-muted-foreground" />

                  <div className="min-w-0 flex-1">
                    <Link to={`/servers/${server.id}`} className="font-medium hover:underline">
                      {server.name}
                    </Link>
                    <p className="truncate text-sm text-muted-foreground">
                      {server.ftp?.host ?? t('servers.notConfigured')}
                    </p>
                  </div>

                  <div className="flex shrink-0 gap-1">
                    {server.ftp?.lastVerifiedAt && (
                      <Badge variant="secondary" className="gap-1">
                        <CheckCircle2 className="size-3" />
                        {server.ftp.protocol.toUpperCase()}
                      </Badge>
                    )}
                    {server.rcon?.lastVerifiedAt && (
                      <Badge variant="secondary" className="gap-1">
                        <CheckCircle2 className="size-3" />
                        RCON
                      </Badge>
                    )}
                  </div>
                </CardContent>
              </Card>
            </li>
          ))}
        </ul>
      )}

      <Dialog open={creating} onOpenChange={(open) => !open && setCreating(false)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('servers.add')}</DialogTitle>
            <DialogDescription>{t('servers.addHint')}</DialogDescription>
          </DialogHeader>

          <div className="space-y-2">
            <Label htmlFor="server-name">{t('servers.name')}</Label>
            <Input
              id="server-name"
              autoFocus
              value={name}
              placeholder="Main Server"
              onChange={(event) => setName(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter' && name.trim() !== '') {
                  create.mutate()
                }
              }}
            />
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setCreating(false)}>
              {t('common.cancel')}
            </Button>
            <Button disabled={name.trim() === '' || create.isPending} onClick={() => create.mutate()}>
              {create.isPending ? t('common.loading') : t('servers.add')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
