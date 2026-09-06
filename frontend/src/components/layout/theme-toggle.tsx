import { useTranslation } from 'react-i18next'
import { Monitor, Moon, Sun } from 'lucide-react'

import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useTheme } from '@/components/theme-provider'

const THEMES = [
  { value: 'light', icon: Sun, label: 'nav.lightTheme' },
  { value: 'dark', icon: Moon, label: 'nav.darkTheme' },
  { value: 'system', icon: Monitor, label: 'nav.systemTheme' },
] as const

export function ThemeToggle() {
  const { t } = useTranslation()
  const { theme, setTheme } = useTheme()

  const active = THEMES.find((entry) => entry.value === theme) ?? THEMES[1]
  const ActiveIcon = active.icon

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" className="size-8" aria-label={t('nav.theme')}>
          <ActiveIcon className="size-4" />
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end" className="w-44">
        {THEMES.map((entry) => (
          <DropdownMenuItem
            key={entry.value}
            // Kept selectable so a click confirms the choice rather than
            // looking broken; the check mark carries the current state.
            onClick={() => setTheme(entry.value)}
          >
            <entry.icon className="size-4" />
            {t(entry.label)}
            {entry.value === theme && <span className="ml-auto text-xs">✓</span>}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
