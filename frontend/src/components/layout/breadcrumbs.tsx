import { useLocation } from 'react-router'
import { useTranslation } from 'react-i18next'

import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbList,
  BreadcrumbPage,
} from '@/components/ui/breadcrumb'

const TITLES: Record<string, string> = {
  '': 'nav.dashboard',
  servers: 'nav.servers',
  settings: 'nav.settings',
  profile: 'nav.profile',
  health: 'nav.health',
}

export function Breadcrumbs() {
  const { t } = useTranslation()
  const { pathname } = useLocation()

  const segment = pathname.replace(/^\//, '').split('/')[0]
  const key = TITLES[segment] ?? 'nav.dashboard'

  return (
    <Breadcrumb>
      <BreadcrumbList>
        <BreadcrumbItem>
          <BreadcrumbPage>{t(key)}</BreadcrumbPage>
        </BreadcrumbItem>
      </BreadcrumbList>
    </Breadcrumb>
  )
}
