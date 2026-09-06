import {
  Car,
  CloudRain,
  Globe,
  MessagesSquare,
  Package,
  ScrollText,
  Map,
  Skull,
  Sparkles,
  Terminal,
  Users,
  Volume2,
  Zap,
} from 'lucide-react'

import type { Permission } from '@/features/auth/types'

/**
 * Which band of the sidebar a page belongs to.
 *
 * Grouped by what somebody is doing rather than by what the page reads
 * from: looking at the server as it runs, changing the world, handing
 * out content, administering the panel. One flat list of eight put
 * looking things up beside intervening beside configuring.
 */
export type ServerSection = 'live' | 'world' | 'content' | 'diagnostics'

/**
 * A page nested under another, for the event categories.
 *
 * No permission of its own: there is one event permission and the
 * parent's filter already gates the whole subtree.
 */
export type ServerChildPage = {
  path: string
  label: string
  icon: typeof Users
}

/** One entry per page under a server, with what it takes to see it. */
export type ServerPage = {
  path: string
  label: string
  icon: typeof Users
  permission: Permission
  section: ServerSection
  children?: ServerChildPage[]
}

/**
 * The five event categories as pages of their own.
 *
 * The labels are the catalogue's own -- `events.categories.<id>` -- so
 * one string serves the nav entry, the breadcrumb and the page heading.
 */
export const EVENT_CHILDREN: ServerChildPage[] = [
  { path: 'weather', label: 'events.categories.weather', icon: CloudRain },
  { path: 'sounds', label: 'events.categories.sounds', icon: Volume2 },
  { path: 'actions', label: 'events.categories.actions', icon: Zap },
  { path: 'zombies', label: 'events.categories.zombies', icon: Skull },
  { path: 'world', label: 'events.categories.world', icon: Globe },
]

export const SERVER_PAGES: ServerPage[] = [
  {
    path: 'players',
    label: 'nav.players',
    icon: Users,
    permission: 'players.view',
    section: 'live',
  },
  { path: 'chat', label: 'nav.chat', icon: MessagesSquare, permission: 'chat.read', section: 'live' },
  {
    path: 'console',
    label: 'nav.console',
    icon: Terminal,
    permission: 'console.use',
    section: 'live',
  },
  {
    path: 'events',
    label: 'nav.events',
    icon: Sparkles,
    permission: 'events.trigger',
    section: 'world',
    children: EVENT_CHILDREN,
  },
  { path: 'map', label: 'nav.map', icon: Map, permission: 'players.view', section: 'world' },
  {
    path: 'items',
    label: 'nav.items',
    icon: Package,
    permission: 'items.give',
    section: 'content',
  },
  {
    path: 'vehicles',
    label: 'nav.vehicles',
    icon: Car,
    permission: 'events.trigger',
    section: 'content',
  },
  // A log is read to find out why something happened, not to hand
  // anything out, so it sits with the diagnostics rather than the content.
  {
    path: 'logs',
    label: 'nav.logs',
    icon: ScrollText,
    permission: 'log.view',
    section: 'diagnostics',
  },
]

/** The bands in the order the sidebar shows them, with their headings. */
export const SERVER_SECTIONS: { id: ServerSection; label: string }[] = [
  { id: 'live', label: 'nav.sectionLive' },
  { id: 'world', label: 'nav.sectionWorld' },
  { id: 'content', label: 'nav.sectionContent' },
  { id: 'diagnostics', label: 'nav.sectionDiagnostics' },
]

export function pagesOf(section: ServerSection): ServerPage[] {
  return SERVER_PAGES.filter((page) => page.section === section)
}
