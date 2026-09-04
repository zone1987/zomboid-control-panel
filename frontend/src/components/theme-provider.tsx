import { createContext, use, useEffect, useMemo, useState } from 'react'

type Theme = 'dark' | 'light' | 'system'

type ThemeContextValue = {
  theme: Theme
  setTheme: (theme: Theme) => void
}

const STORAGE_KEY = 'zomboidcontrol.theme'

/** A server admin panel is read in the dark more often than not. */
const DEFAULT_THEME: Theme = 'dark'

const ThemeContext = createContext<ThemeContextValue | null>(null)

function readStoredTheme(): Theme {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)

    if (stored === 'dark' || stored === 'light' || stored === 'system') {
      return stored
    }
  } catch {
    // Private browsing and blocked site data both throw here.
  }

  return DEFAULT_THEME
}

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [theme, setThemeState] = useState<Theme>(readStoredTheme)

  useEffect(() => {
    const root = document.documentElement
    const media = window.matchMedia('(prefers-color-scheme: dark)')

    const apply = () => {
      const isDark = theme === 'dark' || (theme === 'system' && media.matches)
      root.classList.toggle('dark', isDark)
    }

    apply()

    if (theme !== 'system') {
      return
    }

    media.addEventListener('change', apply)

    return () => media.removeEventListener('change', apply)
  }, [theme])

  const value = useMemo<ThemeContextValue>(
    () => ({
      theme,
      setTheme: (next) => {
        try {
          localStorage.setItem(STORAGE_KEY, next)
        } catch {
          // Losing the preference is acceptable; failing to switch is not.
        }

        setThemeState(next)
      },
    }),
    [theme],
  )

  return <ThemeContext value={value}>{children}</ThemeContext>
}

export function useTheme(): ThemeContextValue {
  const context = use(ThemeContext)

  if (!context) {
    throw new Error('useTheme must be used inside a ThemeProvider.')
  }

  return context
}
