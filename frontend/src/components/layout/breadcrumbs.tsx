import { Fragment } from 'react'
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
import { SERVER_PAGES } from './server-pages'

const TITLES: Record<string, string> = {
  '': 'nav.dashboard',
  servers: 'nav.servers',
  settings: 'nav.settings',
  profile: 'nav.profile',
  health: 'nav.health',
  users: 'nav.users',
  credits: 'nav.credits',
}

// Segments that follow a server id and name a page of their own.
const SUB_PAGE_LABELS: Record<string, string> = Object.fromEntries(
  SERVER_PAGES.map((page) => [page.path, page.label]),
)

export type Crumb = {
  label: string
  to?: string
}

export function crumbsFor(pathname: string): Crumb[] {
  const segments = pathname.replace(/^\//, '').split('/')
  const root = segments[0] ?? ''
  const rootKey = TITLES[root] ?? 'nav.dashboard'
  const subKey = segments.length >= 3 ? SUB_PAGE_LABELS[segments[2]] : undefined

  if (subKey === undefined) {
    return [{ label: rootKey }]
  }

  return [{ label: rootKey, to: `/${root}` }, { label: subKey }]
}

export function Breadcrumbs() {
  const { t } = useTranslation()
  const { pathname } = useLocation()

  const crumbs = crumbsFor(pathname)

  return (
    <Breadcrumb>
      <BreadcrumbList>
        {crumbs.map((crumb, index) => (
          <Fragment key={crumb.label}>
            {index > 0 && <BreadcrumbSeparator />}
            <BreadcrumbItem>
              {crumb.to === undefined ? (
                <BreadcrumbPage>{t(crumb.label)}</BreadcrumbPage>
              ) : (
                <BreadcrumbLink asChild>
                  <Link to={crumb.to}>{t(crumb.label)}</Link>
                </BreadcrumbLink>
              )}
            </BreadcrumbItem>
          </Fragment>
        ))}
      </BreadcrumbList>
    </Breadcrumb>
  )
}
