import { useTranslation } from 'react-i18next'

import { CreditsContent } from './credits-content'

/**
 * The attribution The Indie Stone's terms require of a project using the
 * game's art.
 *
 * A page rather than a footnote: the terms ask for a visible notice, and
 * the panel shows the game's own vehicle models, textures and map tiles
 * throughout. It stays a page even though the footer now opens the same
 * text in a dialog — a dialog cannot be linked to, and a notice nobody
 * can point at is not a notice.
 */
export function CreditsPage() {
  const { t } = useTranslation()

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold">{t('credits.title')}</h1>
        <p className="text-muted-foreground">{t('credits.description')}</p>
      </div>

      <CreditsContent />
    </div>
  )
}
