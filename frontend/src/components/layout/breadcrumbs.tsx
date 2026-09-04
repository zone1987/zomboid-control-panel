import { Link, useLocation } from 'react-router'
import { useTranslation } from 'react-i18next'

import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from '@/components/ui/breadcrumb'

const TITLES: Record<string, string> = {
  '': 'nav.dashboard',
  servers: 'nav.servers',
  settings: 'nav.settings',
  profile: 'nav.profile',
  health: 'nav.health',
  users: 'nav.users',
}

// Segments that follow a server id and name a page of their own.
const SUB_PAGES: Record<string, string> = {
  players: 'nav.players',
  console: 'nav.console',
}

export function Breadcrumbs() {
  const { t } = useTranslation()
  const { pathname } = useLocation()

  const segments = pathname.replace(/^\//, '').split('/')
  const root = segments[0] ?? ''
  const rootKey = TITLES[root] ?? 'nav.dashboard'
  const subKey = segments.length >= 3 ? SUB_PAGES[segments[2]] : undefined

  return (
    <Breadcrumb>
      <BreadcrumbList>
        <BreadcrumbItem>
          {subKey === undefined ? (
            <BreadcrumbPage>{t(rootKey)}</BreadcrumbPage>
          ) : (
            <BreadcrumbLink asChild>
              <Link to={`/${root}`}>{t(rootKey)}</Link>
            </BreadcrumbLink>
          )}
        </BreadcrumbItem>

        {subKey !== undefined && (
          <>
            <BreadcrumbSeparator />
            <BreadcrumbItem>
              <BreadcrumbPage>{t(subKey)}</BreadcrumbPage>
            </BreadcrumbItem>
          </>
        )}
      </BreadcrumbList>
    </Breadcrumb>
  )
}
