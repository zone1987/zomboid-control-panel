import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { CheckCircle2, FolderSearch, PlugZap, Trash2 } from 'lucide-react'

import { ApiError } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { DirectoryBrowser } from './directory-browser'
import {
  deleteServer,
  getServer,
  testFtp,
  testRcon,
  updateServer,
  type ServerDraft,
} from './servers'

export function ServerDetailPage() {
  const { t } = useTranslation()
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [draft, setDraft] = useState<ServerDraft>({})
  const [browsingFor, setBrowsingFor] = useState<'basePath' | 'luaServerPath' | 'logPath' | null>(null)

  const { data: server, isPending } = useQuery({
    queryKey: ['server', id],
    queryFn: () => getServer(id),
  })

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ['server', id] })
    await queryClient.invalidateQueries({ queryKey: ['servers'] })
  }

  const save = useMutation({
    mutationFn: (values: ServerDraft) => updateServer(id, values),
    onSuccess: async () => {
      setDraft({})
      await invalidate()
      toast.success(t('servers.saved'))
    },
    onError: () => toast.error(t('errors.generic')),
  })

  const probeFtp = useMutation({
    mutationFn: () => testFtp(id),
    onSuccess: async (result) => {
      await invalidate()
      toast.success(
        result.looksLikeZomboid
          ? t('servers.ftpOkZomboid', { count: result.entryCount })
          : t('servers.ftpOk', { count: result.entryCount }),
      )
    },
    onError: (error) => toast.error(t(errorKey(error))),
  })

  const probeRcon = useMutation({
    mutationFn: () => testRcon(id),
    onSuccess: async (result) => {
      await invalidate()
      toast.success(t('servers.rconOk', { reply: result.reply.split('\n')[0] }))
    },
    onError: (error) => toast.error(t(errorKey(error))),
  })

  const remove = useMutation({
    mutationFn: () => deleteServer(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['servers'] })
      void navigate('/servers', { replace: true })
    },
    onError: () => toast.error(t('errors.generic')),
  })

  if (isPending || !server) {
    return <Skeleton className="h-96 w-full" />
  }

  const ftpField = (key: keyof NonNullable<ServerDraft['ftp']>, fallback: unknown) =>
    (draft.ftp?.[key] as string | number | undefined) ??
    (fallback === null || fallback === undefined ? '' : fallback)

  const setFtp = (key: keyof NonNullable<ServerDraft['ftp']>, value: string | number) =>
    setDraft((prev) => ({ ...prev, ftp: { ...prev.ftp, [key]: value } }))

  const setRcon = (key: keyof NonNullable<ServerDraft['rcon']>, value: string | number) =>
    setDraft((prev) => ({ ...prev, rcon: { ...prev.rcon, [key]: value } }))

  const hasChanges = Object.keys(draft).length > 0

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold">{server.name}</h1>
          {server.description && <p className="text-muted-foreground">{server.description}</p>}
        </div>

        <Button
          variant="ghost"
          size="icon"
          aria-label={t('common.delete')}
          disabled={remove.isPending}
          onClick={() => {
            if (window.confirm(t('servers.confirmDelete', { name: server.name }))) {
              remove.mutate()
            }
          }}
        >
          <Trash2 className="size-4" />
        </Button>
      </div>

      <Tabs defaultValue="ftp">
        <TabsList>
          <TabsTrigger value="ftp">{t('servers.transferTab')}</TabsTrigger>
          <TabsTrigger value="rcon">RCON</TabsTrigger>
        </TabsList>

        <TabsContent value="ftp">
          <Card>
            <CardHeader>
              <div className="flex items-start justify-between gap-3">
                <div>
                  <CardTitle>{t('servers.transferTitle')}</CardTitle>
                  <CardDescription>{t('servers.transferDescription')}</CardDescription>
                </div>
                {server.ftp?.lastVerifiedAt && (
                  <Badge variant="secondary" className="gap-1">
                    <CheckCircle2 className="size-3" />
                    {t('servers.verified')}
                  </Badge>
                )}
              </div>
            </CardHeader>

            <CardContent className="space-y-4">
              <div className="grid gap-4 sm:grid-cols-[10rem_1fr]">
                <div className="space-y-2">
                  <Label htmlFor="protocol">{t('servers.protocol')}</Label>
                  <Select
                    value={String(ftpField('protocol', server.ftp?.protocol ?? 'sftp'))}
                    onValueChange={(value) => setFtp('protocol', value)}
                  >
                    <SelectTrigger id="protocol">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="sftp">SFTP</SelectItem>
                      <SelectItem value="ftp">FTP</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="ftp-host">{t('servers.host')}</Label>
                  <Input
                    id="ftp-host"
                    value={String(ftpField('host', server.ftp?.host))}
                    placeholder="game.example.com"
                    onChange={(e) => setFtp('host', e.target.value)}
                  />
                </div>
              </div>

              <div className="grid gap-4 sm:grid-cols-[10rem_1fr]">
                <div className="space-y-2">
                  <Label htmlFor="ftp-port">{t('servers.port')}</Label>
                  <Input
                    id="ftp-port"
                    type="number"
                    value={String(ftpField('port', server.ftp?.port ?? 22))}
                    onChange={(e) => setFtp('port', Number(e.target.value))}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="ftp-username">{t('servers.username')}</Label>
                  <Input
                    id="ftp-username"
                    autoComplete="off"
                    value={String(ftpField('username', server.ftp?.username))}
                    onChange={(e) => setFtp('username', e.target.value)}
                  />
                </div>
              </div>

              <div className="space-y-2">
                <div className="flex items-center gap-2">
                  <Label htmlFor="ftp-password">{t('servers.password')}</Label>
                  {server.ftp?.hasPassword && (
                    <Badge variant="secondary" className="text-xs">
                      {t('settings.configured')}
                    </Badge>
                  )}
                </div>
                <Input
                  id="ftp-password"
                  type="password"
                  autoComplete="new-password"
                  placeholder={server.ftp?.hasPassword ? t('settings.unchangedPlaceholder') : ''}
                  value={String(ftpField('password', ''))}
                  onChange={(e) => setFtp('password', e.target.value)}
                />
              </div>

              <PathField
                id="base-path"
                label={t('servers.basePath')}
                value={String(ftpField('basePath', server.ftp?.basePath ?? '/'))}
                onChange={(value) => setFtp('basePath', value)}
                onBrowse={server.ftp ? () => setBrowsingFor('basePath') : undefined}
              />

              <PathField
                id="lua-path"
                label={t('servers.luaServerPath')}
                value={String(ftpField('luaServerPath', server.ftp?.luaServerPath))}
                placeholder="media/lua/server"
                onChange={(value) => setFtp('luaServerPath', value)}
                onBrowse={server.ftp ? () => setBrowsingFor('luaServerPath') : undefined}
              />

              <PathField
                id="log-path"
                label={t('servers.logPath')}
                value={String(ftpField('logPath', server.ftp?.logPath))}
                placeholder="Logs"
                onChange={(value) => setFtp('logPath', value)}
                onBrowse={server.ftp ? () => setBrowsingFor('logPath') : undefined}
              />

              <Button
                variant="outline"
                size="sm"
                disabled={probeFtp.isPending || !server.ftp}
                onClick={() => probeFtp.mutate()}
              >
                <PlugZap className="size-4" />
                {probeFtp.isPending ? t('common.loading') : t('servers.testConnection')}
              </Button>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="rcon">
          <Card>
            <CardHeader>
              <div className="flex items-start justify-between gap-3">
                <div>
                  <CardTitle>{t('servers.rconTitle')}</CardTitle>
                  <CardDescription>{t('servers.rconDescription')}</CardDescription>
                </div>
                {server.rcon?.lastVerifiedAt && (
                  <Badge variant="secondary" className="gap-1">
                    <CheckCircle2 className="size-3" />
                    {t('servers.verified')}
                  </Badge>
                )}
              </div>
            </CardHeader>

            <CardContent className="space-y-4">
              <div className="grid gap-4 sm:grid-cols-[1fr_10rem]">
                <div className="space-y-2">
                  <Label htmlFor="rcon-host">{t('servers.host')}</Label>
                  <Input
                    id="rcon-host"
                    value={String(draft.rcon?.host ?? server.rcon?.host ?? '')}
                    placeholder="game.example.com"
                    onChange={(e) => setRcon('host', e.target.value)}
                  />
                </div>

                <div className="space-y-2">
                  <Label htmlFor="rcon-port">{t('servers.port')}</Label>
                  <Input
                    id="rcon-port"
                    type="number"
                    value={String(draft.rcon?.port ?? server.rcon?.port ?? 27015)}
                    onChange={(e) => setRcon('port', Number(e.target.value))}
                  />
                </div>
              </div>

              <div className="space-y-2">
                <div className="flex items-center gap-2">
                  <Label htmlFor="rcon-password">{t('servers.rconPassword')}</Label>
                  {server.rcon?.hasPassword && (
                    <Badge variant="secondary" className="text-xs">
                      {t('settings.configured')}
                    </Badge>
                  )}
                </div>
                <Input
                  id="rcon-password"
                  type="password"
                  autoComplete="new-password"
                  placeholder={server.rcon?.hasPassword ? t('settings.unchangedPlaceholder') : ''}
                  value={String(draft.rcon?.password ?? '')}
                  onChange={(e) => setRcon('password', e.target.value)}
                />
              </div>

              <Button
                variant="outline"
                size="sm"
                disabled={probeRcon.isPending || !server.rcon}
                onClick={() => probeRcon.mutate()}
              >
                <PlugZap className="size-4" />
                {probeRcon.isPending ? t('common.loading') : t('servers.testConnection')}
              </Button>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      <div className="flex gap-2">
        <Button disabled={!hasChanges || save.isPending} onClick={() => save.mutate(draft)}>
          {save.isPending ? t('common.loading') : t('common.save')}
        </Button>

        {hasChanges && (
          <Button variant="ghost" onClick={() => setDraft({})}>
            {t('common.cancel')}
          </Button>
        )}
      </div>

      {browsingFor && (
        <DirectoryBrowser
          serverId={id}
          open
          title={t('servers.browseTitle')}
          onClose={() => setBrowsingFor(null)}
          onSelect={(path) => setFtp(browsingFor, path)}
        />
      )}
    </div>
  )
}

function PathField({
  id,
  label,
  value,
  placeholder,
  onChange,
  onBrowse,
}: {
  id: string
  label: string
  value: string
  placeholder?: string
  onChange: (value: string) => void
  onBrowse?: () => void
}) {
  const { t } = useTranslation()

  return (
    <div className="space-y-2">
      <Label htmlFor={id}>{label}</Label>
      <div className="flex gap-2">
        <Input
          id={id}
          value={value}
          placeholder={placeholder}
          onChange={(e) => onChange(e.target.value)}
        />
        {onBrowse && (
          <Button type="button" variant="outline" size="icon" aria-label={t('servers.browse')} onClick={onBrowse}>
            <FolderSearch className="size-4" />
          </Button>
        )}
      </div>
    </div>
  )
}

/** Backend failures carry a translation key; anything else is unexpected. */
function errorKey(error: unknown): string {
  if (error instanceof ApiError && typeof error.payload === 'object' && error.payload !== null) {
    const key = (error.payload as { error?: unknown }).error

    if (typeof key === 'string') {
      return key
    }
  }

  return 'errors.generic'
}
