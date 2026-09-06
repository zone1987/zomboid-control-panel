import {
  Car,
  MessagesSquare,
  Package,
  ScrollText,
  Map,
  Sparkles,
  Terminal,
  Users,
} from 'lucide-react'

import type { Permission } from '@/features/auth/types'

/** One entry per page under a server, with what it takes to see it. */
export type ServerPage = {
  path: string
  label: string
  icon: typeof Users
  permission: Permission
}

export const SERVER_PAGES: ServerPage[] = [
  { path: 'players', label: 'nav.players', icon: Users, permission: 'players.view' },
  { path: 'items', label: 'nav.items', icon: Package, permission: 'items.give' },
  { path: 'vehicles', label: 'nav.vehicles', icon: Car, permission: 'events.trigger' },
  { path: 'chat', label: 'nav.chat', icon: MessagesSquare, permission: 'chat.read' },
  { path: 'logs', label: 'nav.logs', icon: ScrollText, permission: 'log.view' },
  { path: 'map', label: 'nav.map', icon: Map, permission: 'players.view' },
  { path: 'events', label: 'nav.events', icon: Sparkles, permission: 'events.trigger' },
  { path: 'console', label: 'nav.console', icon: Terminal, permission: 'console.use' },
]
