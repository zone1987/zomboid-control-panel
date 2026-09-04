import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'

import de from './locales/de.json'
import en from './locales/en.json'

export const SUPPORTED_LANGUAGES = ['de', 'en'] as const

export type SupportedLanguage = (typeof SUPPORTED_LANGUAGES)[number]

const STORAGE_KEY = 'zomboidcontrol.language'

function detectLanguage(): SupportedLanguage {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)

    if (stored && SUPPORTED_LANGUAGES.includes(stored as SupportedLanguage)) {
      return stored as SupportedLanguage
    }
  } catch {
    // Blocked site data falls through to browser detection.
  }

  return navigator.language.startsWith('de') ? 'de' : 'en'
}

void i18n.use(initReactI18next).init({
  resources: {
    de: { translation: de },
    en: { translation: en },
  },
  lng: detectLanguage(),
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
})

export function changeLanguage(language: SupportedLanguage): void {
  try {
    localStorage.setItem(STORAGE_KEY, language)
  } catch {
    // Losing the preference is acceptable; failing to switch is not.
  }

  void i18n.changeLanguage(language)
}

export default i18n
