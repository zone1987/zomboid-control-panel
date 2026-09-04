import { Link, Outlet } from 'react-router'
import { useTranslation } from 'react-i18next'
import { LogOut, Moon, Sun, User } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { useTheme } from '@/components/theme-provider'
import { useAuth } from '@/features/auth/auth-context'
import { changeLanguage, SUPPORTED_LANGUAGES, type SupportedLanguage } from '@/i18n/config'

export function AppLayout() {
  const { t, i18n } = useTranslation()
  const { theme, setTheme } = useTheme()
  const { user, signOut } = useAuth()

  return (
    <div className="min-h-svh bg-background">
      <header className="flex items-center justify-between border-b px-6 py-3">
        <Link to="/" className="font-semibold">
          {t('common.appName')}
        </Link>

        <div className="flex items-center gap-2">
          <select
            className="h-9 rounded-md border bg-background px-2 text-sm"
            value={i18n.language.slice(0, 2)}
            onChange={(event) => changeLanguage(event.target.value as SupportedLanguage)}
            aria-label={t('profile.language')}
          >
            {SUPPORTED_LANGUAGES.map((language) => (
              <option key={language} value={language}>
                {language.toUpperCase()}
              </option>
            ))}
          </select>

          <Button
            variant="ghost"
            size="icon"
            aria-label="Toggle theme"
            onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
          >
            {theme === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
          </Button>

          <Button variant="ghost" size="icon" aria-label={t('nav.profile')} asChild>
            <Link to="/profile">
              <User className="size-4" />
            </Link>
          </Button>

          {user && (
            <Button variant="ghost" size="icon" aria-label={t('auth.signOut')} onClick={() => void signOut()}>
              <LogOut className="size-4" />
            </Button>
          )}
        </div>
      </header>

      <main className="p-6">
        <Outlet />
      </main>
    </div>
  )
}
