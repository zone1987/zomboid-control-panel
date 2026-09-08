import { useTranslation } from 'react-i18next'
import { Languages } from 'lucide-react'

import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  changeLanguage,
  LANGUAGE_NAMES,
  SUPPORTED_LANGUAGES,
  UNREVIEWED_LANGUAGES,
  type SupportedLanguage,
} from '@/i18n/config'

export function LanguageToggle() {
  const { t, i18n } = useTranslation()

  const reviewed = SUPPORTED_LANGUAGES.filter((l) => !UNREVIEWED_LANGUAGES.includes(l))
  const unreviewed = SUPPORTED_LANGUAGES.filter((l) => UNREVIEWED_LANGUAGES.includes(l))

  const item = (language: SupportedLanguage) => {
    const active = i18n.language.startsWith(language)

    return (
      <DropdownMenuItem
        key={language}
        className="gap-2"
        onClick={() => void changeLanguage(language)}
      >
        <span className="w-5 shrink-0 text-center text-xs font-medium text-muted-foreground">
          {language.toUpperCase()}
        </span>
        <span className="flex-1">{LANGUAGE_NAMES[language]}</span>
        {active && <span aria-hidden className="text-xs">✓</span>}
        <span className="sr-only">{active ? t('nav.languageActive') : ''}</span>
      </DropdownMenuItem>
    )
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" className="size-8" aria-label={t('nav.language')}>
          <Languages className="size-4" />
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end" className="w-56">
        {reviewed.map(item)}

        <DropdownMenuSeparator />

        {/* Separated and labelled rather than mixed in: these were
            machine-translated and nobody has read them as a native
            speaker, which the reader deserves to know before choosing
            one. */}
        <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
          {t('nav.languageUnreviewed')}
        </DropdownMenuLabel>

        {unreviewed.map(item)}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
