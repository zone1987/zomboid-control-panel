import { useQuery } from '@tanstack/react-query'
import { Link, useLocation } from 'react-router'
import { useTranslation } from 'react-i18next'
import {
  ChevronRight,
  ChevronsUpDown,
  LayoutDashboard,
  LogOut,
  Plus,
  Server,
  Settings,
  User,
  Users,
} from 'lucide-react'

import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuAction,
  SidebarMenuItem,
  SidebarMenuSub,
  SidebarMenuSubButton,
  SidebarMenuSubItem,
  SidebarRail,
} from '@/components/ui/sidebar'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { useAuth } from '@/features/auth/auth-context'
import { listServers } from '@/features/servers/servers'
import { useActiveServer } from '@/features/servers/active-server'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible'
import { BrandLogo } from '@/components/brand-logo'
import { pagesOf, SERVER_SECTIONS } from './server-pages'

export function AppSidebar() {
  const { t } = useTranslation()
  const { pathname } = useLocation()
  const { user, signOut, can } = useAuth()
  const { activeServerId, setActiveServerId } = useActiveServer()

  const { data: servers } = useQuery({
    queryKey: ['servers'],
    queryFn: listServers,
    enabled: can('servers.view') || can('players.view'),
  })

  const activeServer =
    servers?.items.find((server) => server.id === activeServerId) ?? servers?.items[0]

  const isActive = (path: string) =>
    path === '/' ? pathname === '/' : pathname.startsWith(path)

  // A child needs exact matching: /events/weather must not light up
  // /events as well as itself, and the parent keeps the prefix match so
  // it stays lit while a child is open.
  const isExactly = (path: string) => pathname === path || pathname === `${path}/`

  return (
    <Sidebar collapsible="icon">
      <SidebarHeader>
        <SidebarMenu>
          <SidebarMenuItem>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <SidebarMenuButton size="lg" tooltip={activeServer?.name ?? t('servers.noneSelected')}>
                  <BrandLogo variant="mark" priority className="size-8 shrink-0" />
                  <div className="grid flex-1 text-left text-sm leading-tight">
                    <span className="truncate font-medium">
                      {activeServer?.name ?? t('common.appName')}
                    </span>
                    <span className="truncate text-xs text-muted-foreground">
                      {activeServer?.ftp?.host ?? t('servers.noneSelected')}
                    </span>
                  </div>
                  <ChevronsUpDown className="ml-auto size-4" />
                </SidebarMenuButton>
              </DropdownMenuTrigger>

              <DropdownMenuContent align="start" className="w-56">
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                  {t('servers.title')}
                </DropdownMenuLabel>

                {servers?.items.map((server) => (
                  <DropdownMenuItem key={server.id} onClick={() => setActiveServerId(server.id)}>
                    <Server className="size-4" />
                    {server.name}
                  </DropdownMenuItem>
                ))}

                {servers?.items.length === 0 && (
                  <DropdownMenuItem disabled>{t('servers.empty')}</DropdownMenuItem>
                )}

                <DropdownMenuSeparator />

                <DropdownMenuItem asChild>
                  <Link to="/servers">
                    <Plus className="size-4" />
                    {t('servers.manage')}
                  </Link>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>

      <SidebarContent>
        <SidebarGroup>
          <SidebarGroupLabel>{t('nav.overview')}</SidebarGroupLabel>
          <SidebarMenu>
            <SidebarMenuItem>
              <SidebarMenuButton asChild isActive={isActive('/')} tooltip={t('nav.dashboard')}>
                <Link to="/">
                  <LayoutDashboard />
                  <span>{t('nav.dashboard')}</span>
                </Link>
              </SidebarMenuButton>
            </SidebarMenuItem>
          </SidebarMenu>
        </SidebarGroup>

        {(can('servers.view') || can('players.view')) && (
          <SidebarGroup>
            <SidebarGroupLabel>{t('nav.serverSection')}</SidebarGroupLabel>
            <SidebarMenu>
              <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={isActive('/servers')} tooltip={t('nav.servers')}>
                  <Link to="/servers">
                    <Server />
                    <span>{t('nav.servers')}</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarGroup>
        )}

        {SERVER_SECTIONS.map((section) => {
          const pages = pagesOf(section.id).filter((page) => can(page.permission))

          // A heading with nothing under it is worse than no heading.
          if (pages.length === 0) {
            return null
          }

          return (
            <SidebarGroup key={section.id}>
              <SidebarGroupLabel>{t(section.label)}</SidebarGroupLabel>
              <SidebarMenu>
                {pages.map((page) => {
                  if (activeServer === undefined) {
                    return (
                      <SidebarMenuItem key={page.path}>
                        <SidebarMenuButton disabled tooltip={t('nav.noServerYet')}>
                          <page.icon />
                          <span>{t(page.label)}</span>
                        </SidebarMenuButton>
                      </SidebarMenuItem>
                    )
                  }

                  const base = `/servers/${activeServer.id}/${page.path}`

                  if (page.children === undefined) {
                    return (
                      <SidebarMenuItem key={page.path}>
                        <SidebarMenuButton
                          asChild
                          isActive={isActive(base)}
                          tooltip={t(page.label)}
                        >
                          <Link to={base}>
                            <page.icon />
                            <span>{t(page.label)}</span>
                          </Link>
                        </SidebarMenuButton>
                      </SidebarMenuItem>
                    )
                  }

                  return (
                    <Collapsible
                      key={page.path}
                      asChild
                      // Open on arrival when a child is showing, so a deep
                      // link does not land with its own entry hidden.
                      defaultOpen={isActive(base)}
                      className="group/collapsible"
                    >
                      <SidebarMenuItem>
                        {/* The label still navigates and the chevron still
                            toggles: two jobs, two controls, rather than a
                            label that only opens a list. */}
                        <SidebarMenuButton
                          asChild
                          isActive={isExactly(base)}
                          tooltip={t(page.label)}
                        >
                          <Link to={base}>
                            <page.icon />
                            <span>{t(page.label)}</span>
                          </Link>
                        </SidebarMenuButton>

                        <CollapsibleTrigger asChild>
                          <SidebarMenuAction
                            aria-label={t('nav.toggleSection', { section: t(page.label) })}
                            className="data-[state=open]:rotate-90"
                          >
                            <ChevronRight />
                          </SidebarMenuAction>
                        </CollapsibleTrigger>

                        <CollapsibleContent>
                          <SidebarMenuSub>
                            {page.children.map((child) => (
                              <SidebarMenuSubItem key={child.path}>
                                <SidebarMenuSubButton
                                  asChild
                                  isActive={isExactly(`${base}/${child.path}`)}
                                >
                                  <Link to={`${base}/${child.path}`}>
                                    <child.icon />
                                    <span>{t(child.label)}</span>
                                  </Link>
                                </SidebarMenuSubButton>
                              </SidebarMenuSubItem>
                            ))}
                          </SidebarMenuSub>
                        </CollapsibleContent>
                      </SidebarMenuItem>
                    </Collapsible>
                  )
                })}
              </SidebarMenu>
            </SidebarGroup>
          )
        })}

        {(can('users.manage') || can('users.invite') || can('settings.edit')) && (
          <SidebarGroup>
            <SidebarGroupLabel>{t('nav.administration')}</SidebarGroupLabel>
            <SidebarMenu>
              <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={isActive('/settings')} tooltip={t('nav.settings')}>
                  <Link to="/settings">
                    <Settings />
                    <span>{t('nav.settings')}</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>

              <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={isActive('/users')} tooltip={t('nav.users')}>
                  <Link to="/users">
                    <Users />
                    <span>{t('nav.users')}</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarGroup>
        )}
      </SidebarContent>

      <SidebarFooter>
        <SidebarMenu>
          <SidebarMenuItem>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <SidebarMenuButton size="lg" tooltip={user?.displayName ?? ''}>
                  <Avatar className="size-8 rounded-lg">
                    <AvatarFallback className="rounded-lg">
                      {initials(user?.displayName ?? '?')}
                    </AvatarFallback>
                  </Avatar>
                  <div className="grid flex-1 text-left text-sm leading-tight">
                    <span className="truncate font-medium">{user?.displayName}</span>
                    <span className="truncate text-xs text-muted-foreground">{user?.email}</span>
                  </div>
                  <ChevronsUpDown className="ml-auto size-4" />
                </SidebarMenuButton>
              </DropdownMenuTrigger>

              <DropdownMenuContent align="end" side="top" className="w-56">
                <DropdownMenuItem asChild>
                  <Link to="/profile">
                    <User className="size-4" />
                    {t('nav.profile')}
                  </Link>
                </DropdownMenuItem>

                <DropdownMenuSeparator />

                <DropdownMenuItem onClick={() => void signOut()}>
                  <LogOut className="size-4" />
                  {t('auth.signOut')}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarFooter>

      <SidebarRail />
    </Sidebar>
  )
}

function initials(name: string): string {
  return name
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('')
}
