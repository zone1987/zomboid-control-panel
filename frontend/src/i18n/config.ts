import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'

import en from './locales/en.json'

export const SUPPORTED_LANGUAGES = ['de', 'en', 'es', 'fr', 'it', 'pl', 'ru'] as const

export type SupportedLanguage = (typeof SUPPORTED_LANGUAGES)[number]

/**
 * Languages nobody has reviewed as a native speaker.
 *
 * Said out loud in the switcher rather than left for the reader to
 * discover: a clumsy sentence is easier to forgive when it did not
 * claim to be finished.
 */
export const UNREVIEWED_LANGUAGES: readonly SupportedLanguage[] = ['es', 'fr', 'it', 'pl', 'ru']

/** What each language calls itself. */
export const LANGUAGE_NAMES: Record<SupportedLanguage, string> = {
  de: 'Deutsch',
  en: 'English',
  es: 'Español',
  fr: 'Français',
  it: 'Italiano',
  pl: 'Polski',
  ru: 'Русский',
}

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

  // `navigator.language` is a tag like `pt-BR`, so match on the primary
  // subtag: `de-AT` is German, and `de` on its own is too.
  const primary = navigator.language.split('-')[0]

  return SUPPORTED_LANGUAGES.includes(primary as SupportedLanguage)
    ? (primary as SupportedLanguage)
    : 'en'
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

/**
 * Keeps the document's language attribute on whatever i18next resolved.
 *
 * Screen readers take their pronunciation from it, and every path --
 * first detection, the fallback, a manual switch -- ends in this event,
 * which is why it is hooked here rather than set at each call site.
 */
i18n.on('languageChanged', (language) => {
  document.documentElement.lang = language.split('-')[0]
})

export default i18n
