import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { useActiveServer } from '@/features/servers/active-server'
import { listServers } from '@/features/servers/servers'
import { getConnections } from '@/features/servers/connections'

/**
 * Which bridge the game server is actually running.
 *
 * Read from the connections endpoint the lights already poll, so this
 * costs no request of its own. Silent when there is no server or no
 * bridge installed: the light beside it already says the bridge is
 * missing, and a version line reading nothing would say it twice.
 */
export function BridgeVersionLine() {
  const { t } = useTranslation()
  const { activeServerId } = useActiveServer()

  const { data: servers } = useQuery({ queryKey: ['servers'], queryFn: listServers })
  const serverId =
    servers?.items.find((server) => server.id === activeServerId)?.id ?? servers?.items[0]?.id

  const { data } = useQuery({
    queryKey: ['connections', serverId],
    queryFn: () => getConnections(serverId as string),
    enabled: serverId !== undefined,
    retry: false,
  })

  if (data?.bridge.version == null) {
    return null
  }

  return (
    <p className="font-mono text-xs text-muted-foreground">
      <span className="font-sans">{t('connections.bridge')} </span>v{data.bridge.version}
    </p>
  )
}
