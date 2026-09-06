import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'

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

/**
 * Fetches a translation table and registers it.
 *
 * English ships with the entry chunk because it is the fallback and any
 * missing key resolves against it. Every other language is a separate
 * chunk, so a German session never downloads English strings it will not
 * show, and vice versa.
 */
async function loadLanguage(language: SupportedLanguage): Promise<void> {
  if (i18n.hasResourceBundle(language, 'translation')) {
    return
  }

  const table = await import(`./locales/${language}.json`)

  i18n.addResourceBundle(language, 'translation', table.default, true, true)
}

const initial = detectLanguage()

void i18n
  .use(initReactI18next)
  .init({
    resources: { en: { translation: en } },
    lng: initial,
    fallbackLng: 'en',
    interpolation: { escapeValue: false },
  })
  .then(async () => {
    if (initial !== 'en') {
      await loadLanguage(initial)
      // The bundle arrives after init, so the tree has to be told.
      await i18n.changeLanguage(initial)
    }
  })

export async function changeLanguage(language: SupportedLanguage): Promise<void> {
  try {
    localStorage.setItem(STORAGE_KEY, language)
  } catch {
    // Losing the preference is acceptable; failing to switch is not.
  }

  await loadLanguage(language)
  await i18n.changeLanguage(language)
}

export default i18n
