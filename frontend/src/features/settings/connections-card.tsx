import { useTranslation } from 'react-i18next'
import { ExternalLink, Globe, Monitor, Server } from 'lucide-react'

import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

/**
 * Which way the call goes, because it decides whose address is exposed.
 *
 * A request the panel makes carries the panel's address; one the
 * browser makes carries the operator's own. Collapsing the two into
 * "the panel talks to Steam" would hide the only part that matters for
 * data protection.
 */
type Origin = 'panel' | 'browser'

type Connection = {
  id: string
  host: string
  origin: Origin
  /** Only where the service is optional, so the entry can say "off". */
  optional?: boolean
  href?: string
}

const CONNECTIONS: Connection[] = [
  { id: 'steamLogin', host: 'steamcommunity.com', origin: 'browser', optional: true },
  { id: 'steamApi', host: 'api.steampowered.com', origin: 'panel', optional: true },
  { id: 'mapTiles', host: 'tiles.projectzomboidmap.com', origin: 'browser', href: 'https://projectzomboidmap.com' },
  { id: 'github', host: 'api.github.com', origin: 'panel' },
  { id: 'discord', host: 'discord.com', origin: 'panel', optional: true },
  { id: 'google', host: 'accounts.google.com', origin: 'browser', optional: true },
  { id: 'coolify', host: '—', origin: 'panel', optional: true },
  { id: 'gameServer', host: '—', origin: 'panel' },
]

export function ConnectionsCard() {
  const { t } = useTranslation()

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settings.connectionsTitle')}</CardTitle>
        <CardDescription>{t('settings.connectionsDescription')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <ul className="space-y-3">
          {CONNECTIONS.map((connection) => (
            <li key={connection.id} className="space-y-1 border-b pb-3 last:border-0 last:pb-0">
              {/* Wraps rather than truncating: a hostname the operator
                  cannot read in full is no use in a privacy notice. */}
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{t(`settings.connection.${connection.id}`)}</span>

                {/* Neutral, because neither direction is good or bad --
                    they are simply different facts (rule 10k). */}
                <Badge variant="outline" className="gap-1 text-xs font-normal">
                  {connection.origin === 'browser' ? (
                    <Monitor className="size-3" />
                  ) : (
                    <Server className="size-3" />
                  )}
                  {t(`settings.origin.${connection.origin}`)}
                </Badge>

                {connection.optional && (
                  <Badge variant="outline" className="text-xs font-normal">
                    {t('settings.connectionOptional')}
                  </Badge>
                )}
              </div>

              <p className="text-sm text-muted-foreground">
                {t(`settings.connectionWhat.${connection.id}`)}
              </p>

              {connection.host !== '—' && (
                <p className="flex flex-wrap items-center gap-1.5 font-mono text-xs text-muted-foreground">
                  <Globe className="size-3 shrink-0" />
                  <span className="break-all">{connection.host}</span>

                  {connection.href !== undefined && (
                    <a
                      href={connection.href}
                      target="_blank"
                      rel="noreferrer"
                      className="inline-flex items-center gap-1 text-primary hover:underline"
                    >
                      <ExternalLink className="size-3" />
                    </a>
                  )}
                </p>
              )}
            </li>
          ))}
        </ul>

        <p className="text-xs text-muted-foreground">{t('settings.connectionsNoTracking')}</p>
      </CardContent>
    </Card>
  )
}
