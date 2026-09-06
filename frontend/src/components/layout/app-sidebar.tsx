import { useQuery } from '@tanstack/react-query'
import { Link, useLocation } from 'react-router'
import { useTranslation } from 'react-i18next'
import {
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
  SidebarMenuItem,
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
import { BrandLogo } from '@/components/brand-logo'
import { PanelVersionLine } from './panel-version-line'
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
                {pages.map((page) => (
                  <SidebarMenuItem key={page.path}>
                    {activeServer === undefined ? (
                      <SidebarMenuButton disabled tooltip={t('nav.noServerYet')}>
                        <page.icon />
                        <span>{t(page.label)}</span>
                      </SidebarMenuButton>
                    ) : (
                      <SidebarMenuButton
                        asChild
                        isActive={isActive(`/servers/${activeServer.id}/${page.path}`)}
                        tooltip={t(page.label)}
                      >
                        <Link to={`/servers/${activeServer.id}/${page.path}`}>
                          <page.icon />
                          <span>{t(page.label)}</span>
                        </Link>
                      </SidebarMenuButton>
                    )}
                  </SidebarMenuItem>
                ))}
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
        <PanelVersionLine />

        {/* The Indie Stone's terms ask for a visible notice, and the
            panel shows the game's own art throughout. */}
        <Link
          to="/credits"
          className="px-2 pb-1 text-xs text-muted-foreground hover:text-foreground hover:underline group-data-[collapsible=icon]:hidden"
        >
          {t('nav.credits')}
        </Link>
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
