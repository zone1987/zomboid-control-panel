import { useQuery } from '@tanstack/react-query'
import { Link, useLocation } from 'react-router'
import { useTranslation } from 'react-i18next'
import {
  ChevronsUpDown,
  LayoutDashboard,
  MessagesSquare,
  Package,
  LogOut,
  Moon,
  Plus,
  ScrollText,
  Server,
  Settings,
  Sun,
  Terminal,
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
import { useTheme } from '@/components/theme-provider'
import { changeLanguage, SUPPORTED_LANGUAGES, type SupportedLanguage } from '@/i18n/config'
import { listServers } from '@/features/servers/servers'
import { useActiveServer } from '@/features/servers/active-server'

export function AppSidebar() {
  const { t, i18n } = useTranslation()
  const { pathname } = useLocation()
  const { user, signOut, hasRole } = useAuth()
  const { theme, setTheme } = useTheme()
  const { activeServerId, setActiveServerId } = useActiveServer()

  const { data: servers } = useQuery({
    queryKey: ['servers'],
    queryFn: listServers,
    enabled: hasRole('ROLE_SERVER_ADMIN'),
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
                  <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground">
                    <Server className="size-4" />
                  </div>
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

        {hasRole('ROLE_SERVER_ADMIN') && (
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

              <SidebarMenuItem>
                {activeServer === undefined ? (
                  <SidebarMenuButton disabled tooltip={t('nav.noServerYet')}>
                    <Users />
                    <span>{t('nav.players')}</span>
                  </SidebarMenuButton>
                ) : (
                  <SidebarMenuButton
                    asChild
                    isActive={isActive(`/servers/${activeServer.id}/players`)}
                    tooltip={t('nav.players')}
                  >
                    <Link to={`/servers/${activeServer.id}/players`}>
                      <Users />
                      <span>{t('nav.players')}</span>
                    </Link>
                  </SidebarMenuButton>
                )}
              </SidebarMenuItem>

              <SidebarMenuItem>
                {activeServer === undefined ? (
                  <SidebarMenuButton disabled tooltip={t('nav.noServerYet')}>
                    <Package />
                    <span>{t('nav.items')}</span>
                  </SidebarMenuButton>
                ) : (
                  <SidebarMenuButton
                    asChild
                    isActive={isActive(`/servers/${activeServer.id}/items`)}
                    tooltip={t('nav.items')}
                  >
                    <Link to={`/servers/${activeServer.id}/items`}>
                      <Package />
                      <span>{t('nav.items')}</span>
                    </Link>
                  </SidebarMenuButton>
                )}
              </SidebarMenuItem>

              <SidebarMenuItem>
                {activeServer === undefined ? (
                  <SidebarMenuButton disabled tooltip={t('nav.noServerYet')}>
                    <MessagesSquare />
                    <span>{t('nav.chat')}</span>
                  </SidebarMenuButton>
                ) : (
                  <SidebarMenuButton
                    asChild
                    isActive={isActive(`/servers/${activeServer.id}/chat`)}
                    tooltip={t('nav.chat')}
                  >
                    <Link to={`/servers/${activeServer.id}/chat`}>
                      <MessagesSquare />
                      <span>{t('nav.chat')}</span>
                    </Link>
                  </SidebarMenuButton>
                )}
              </SidebarMenuItem>

              <SidebarMenuItem>
                {activeServer === undefined ? (
                  <SidebarMenuButton disabled tooltip={t('nav.noServerYet')}>
                    <ScrollText />
                    <span>{t('nav.logs')}</span>
                  </SidebarMenuButton>
                ) : (
                  <SidebarMenuButton
                    asChild
                    isActive={isActive(`/servers/${activeServer.id}/logs`)}
                    tooltip={t('nav.logs')}
                  >
                    <Link to={`/servers/${activeServer.id}/logs`}>
                      <ScrollText />
                      <span>{t('nav.logs')}</span>
                    </Link>
                  </SidebarMenuButton>
                )}
              </SidebarMenuItem>

              <SidebarMenuItem>
                {activeServer === undefined ? (
                  <SidebarMenuButton disabled tooltip={t('nav.noServerYet')}>
                    <Terminal />
                    <span>{t('nav.console')}</span>
                  </SidebarMenuButton>
                ) : (
                  <SidebarMenuButton
                    asChild
                    isActive={isActive(`/servers/${activeServer.id}/console`)}
                    tooltip={t('nav.console')}
                  >
                    <Link to={`/servers/${activeServer.id}/console`}>
                      <Terminal />
                      <span>{t('nav.console')}</span>
                    </Link>
                  </SidebarMenuButton>
                )}
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarGroup>
        )}

        {hasRole('ROLE_ADMIN') && (
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

                <DropdownMenuItem onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}>
                  {theme === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
                  {theme === 'dark' ? t('nav.lightTheme') : t('nav.darkTheme')}
                </DropdownMenuItem>

                {SUPPORTED_LANGUAGES.map((language) => (
                  <DropdownMenuItem
                    key={language}
                    disabled={i18n.language.startsWith(language)}
                    onClick={() => changeLanguage(language as SupportedLanguage)}
                  >
                    <span className="w-4 text-center text-xs font-medium">
                      {language.toUpperCase()}
                    </span>
                    {t(`nav.language_${language}`)}
                  </DropdownMenuItem>
                ))}

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
