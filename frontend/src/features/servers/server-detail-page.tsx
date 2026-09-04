import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { CircleCheckBig, FolderSearch, PlugZap, Trash2, Upload } from 'lucide-react'

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
  installBridge,
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

  /**
   * Saving verifies whatever credentials it just stored, but a failed
   * check never discards the input: a restarting game server would
   * otherwise cost the operator everything they typed.
   */
  const save = useMutation({
    mutationFn: async (values: ServerDraft) => {
      await updateServer(id, values)

      const checks: Array<{ area: 'ftp' | 'rcon'; error: string }> = []

      if (values.ftp) {
        try {
          await testFtp(id)
        } catch (error) {
          checks.push({ area: 'ftp', error: errorKey(error) })
        }
      }

      if (values.rcon) {
        try {
          await testRcon(id)
        } catch (error) {
          checks.push({ area: 'rcon', error: errorKey(error) })
        }
      }

      return checks
    },
    onSuccess: async (failures) => {
      setDraft({})
      await invalidate()

      if (failures.length === 0) {
        toast.success(t('servers.savedAndVerified'))

        return
      }

      for (const failure of failures) {
        toast.warning(
          t(failure.area === 'ftp' ? 'servers.savedButFtpFailed' : 'servers.savedButRconFailed', {
            reason: t(failure.error),
          }),
          { duration: 10_000 },
        )
      }
    },
    onError: () => toast.error(t('errors.generic')),
  })

  const probeFtp = useMutation({
    // Testing the stored credentials while the form holds newer ones would
    // report on the wrong values, so pending edits are saved first.
    mutationFn: async () => {
      if (draft.ftp) {
        await updateServer(id, { ftp: draft.ftp })
        setDraft((prev) => ({ ...prev, ftp: undefined }))
        await invalidate()
      }

      return testFtp(id)
    },
    onSuccess: async (result) => {
      await invalidate()
      toast.success(
        result.looksLikeZomboid
          ? t('servers.ftpOkZomboid', { count: result.entryCount })
          : t('servers.ftpOk', { count: result.entryCount }),
      )
    },
    onError: (error) => toast.error(t(errorKey(error)), { duration: 10_000 }),
  })

  const probeRcon = useMutation({
    mutationFn: async () => {
      if (draft.rcon) {
        await updateServer(id, { rcon: draft.rcon })
        setDraft((prev) => ({ ...prev, rcon: undefined }))
        await invalidate()
      }

      return testRcon(id)
    },
    onSuccess: async (result) => {
      await invalidate()
      toast.success(t('servers.rconOk', { reply: result.reply.split('\n')[0] }))
    },
    onError: (error) => toast.error(t(errorKey(error)), { duration: 10_000 }),
  })

  const uploadBridge = useMutation({
    mutationFn: () => installBridge(id),
    onSuccess: (result) =>
      toast.success(t('servers.bridgeInstalled', { path: result.path, version: result.version }), {
        duration: 10_000,
      }),
    onError: (error) => toast.error(t(errorKey(error)), { duration: 10_000 }),
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

  // A test is possible as soon as host, user and a secret exist, whether
  // they were just typed or are already stored.
  const ftpReady =
    String(ftpField('host', server.ftp?.host)).trim() !== '' &&
    String(ftpField('username', server.ftp?.username)).trim() !== '' &&
    (Boolean(draft.ftp?.password) || Boolean(draft.ftp?.privateKey) ||
      Boolean(server.ftp?.hasPassword) || Boolean(server.ftp?.hasPrivateKey))

  const rconReady =
    String(draft.rcon?.host ?? server.rcon?.host ?? '').trim() !== '' &&
    (Boolean(draft.rcon?.password) || Boolean(server.rcon?.hasPassword))

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
                {server.ftp?.lastVerifiedAt && <VerifiedBadge label={t('servers.verified')} />}
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
                onBrowse={server.ftp?.lastVerifiedAt ? () => setBrowsingFor('basePath') : undefined}
              />

              <PathField
                id="lua-path"
                label={t('servers.luaServerPath')}
                value={String(ftpField('luaServerPath', server.ftp?.luaServerPath))}
                placeholder="media/lua/server"
                onChange={(value) => setFtp('luaServerPath', value)}
                onBrowse={server.ftp?.lastVerifiedAt ? () => setBrowsingFor('luaServerPath') : undefined}
              />

              <PathField
                id="log-path"
                label={t('servers.logPath')}
                value={String(ftpField('logPath', server.ftp?.logPath))}
                placeholder="Logs"
                onChange={(value) => setFtp('logPath', value)}
                onBrowse={server.ftp?.lastVerifiedAt ? () => setBrowsingFor('logPath') : undefined}
              />

              <div className="space-y-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={probeFtp.isPending || !ftpReady}
                  onClick={() => probeFtp.mutate()}
                >
                  <PlugZap className="size-4" />
                  {probeFtp.isPending ? t('common.loading') : t('servers.testConnection')}
                </Button>

                {!ftpReady && (
                  <p className="text-sm text-muted-foreground">{t('servers.fillBeforeTesting')}</p>
                )}
              </div>
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
                {server.rcon?.lastVerifiedAt && <VerifiedBadge label={t('servers.verified')} />}
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

              <div className="space-y-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={probeRcon.isPending || !rconReady}
                  onClick={() => probeRcon.mutate()}
                >
                  <PlugZap className="size-4" />
                  {probeRcon.isPending ? t('common.loading') : t('servers.testConnection')}
                </Button>

                {!rconReady && (
                  <p className="text-sm text-muted-foreground">{t('servers.fillBeforeTestingRcon')}</p>
                )}
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      <Card>
        <CardHeader>
          <CardTitle>{t('servers.bridgeTitle')}</CardTitle>
          <CardDescription>{t('servers.bridgeDescription')}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-3">
          <Button
            variant="outline"
            disabled={uploadBridge.isPending || !server.ftp?.luaServerPath}
            onClick={() => uploadBridge.mutate()}
          >
            <Upload className="size-4" />
            {uploadBridge.isPending ? t('common.loading') : t('servers.uploadBridge')}
          </Button>

          {!server.ftp?.luaServerPath && (
            <p className="text-sm text-muted-foreground">{t('servers.bridgeNeedsPath')}</p>
          )}

          <p className="text-sm text-muted-foreground">{t('servers.bridgeRestartHint')}</p>
        </CardContent>
      </Card>

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

/** Matches the list view, so a verified connection looks the same everywhere. */
function VerifiedBadge({ label }: { label: string }) {
  return (
    <Badge
      variant="secondary"
      className="gap-1 border-emerald-600/30 bg-emerald-600/15 text-emerald-700 dark:border-emerald-400/30 dark:bg-emerald-400/15 dark:text-emerald-300"
    >
      <CircleCheckBig className="size-3 fill-emerald-600/25 dark:fill-emerald-400/25" />
      {label}
    </Badge>
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
