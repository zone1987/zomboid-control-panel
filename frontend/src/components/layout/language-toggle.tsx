import { useTranslation } from 'react-i18next'
import { Languages } from 'lucide-react'

import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { changeLanguage, SUPPORTED_LANGUAGES, type SupportedLanguage } from '@/i18n/config'

export function LanguageToggle() {
  const { t, i18n } = useTranslation()

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" className="size-8" aria-label={t('nav.language')}>
          <Languages className="size-4" />
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end" className="w-44">
        {SUPPORTED_LANGUAGES.map((language) => {
          const active = i18n.language.startsWith(language)

          return (
            <DropdownMenuItem
              key={language}
              onClick={() => void changeLanguage(language as SupportedLanguage)}
            >
              <span className="w-4 text-center text-xs font-medium">
                {language.toUpperCase()}
              </span>
              {t(`nav.language_${language}`)}
              {active && <span className="ml-auto text-xs">✓</span>}
            </DropdownMenuItem>
          )
        })}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
