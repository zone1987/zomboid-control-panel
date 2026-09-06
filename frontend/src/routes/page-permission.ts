import type { Permission } from '@/features/auth/types'
import { SERVER_PAGES } from '@/components/layout/server-pages'

/**
 * Which permission a server path needs, from `SERVER_PAGES`.
 *
 * Matched on the segment after the server id, so
 * `/servers/<uuid>/events/weather` resolves through `events` — a child
 * page carries no permission of its own, because there is one event
 * permission and the parent gates the subtree.
 */
export function permissionForPath(pathname: string): Permission | null {
  const match = /^\/servers\/[^/]+\/([^/]+)/.exec(pathname)

  if (match === null) {
    return null
  }

  return SERVER_PAGES.find((page) => page.path === match[1])?.permission ?? null
}
