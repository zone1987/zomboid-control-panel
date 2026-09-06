import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ExternalLink } from 'lucide-react'

import { SectionMark } from '@/components/layout/section-mark'

const PROJECT_ZOMBOID = 'https://projectzomboid.com'
const INDIE_STONE_TERMS = 'https://theindiestone.com/forums/index.php?/tos/'

/**
 * The attribution The Indie Stone's terms require of a project using the
 * game's art.
 *
 * A page rather than a footnote: the terms ask for a visible notice, and
 * the panel shows the game's own vehicle models, textures and map tiles
 * throughout. The notice is translated, because one nobody understands
 * does not do its job; the terms' own English wording is kept verbatim
 * in a foldout so it is still on the page.
 */
export function CreditsPage() {
  const { t } = useTranslation()

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold">{t('credits.title')}</h1>
        <p className="text-muted-foreground">{t('credits.description')}</p>
      </div>

      <section className="max-w-2xl space-y-3 rounded-md border p-4">
        <SectionMark label="Project Zomboid" />

        <p className="text-sm leading-relaxed">{t('credits.thanks')}</p>

        <p className="text-sm leading-relaxed">{t('credits.unofficial')}</p>

        <p className="text-sm leading-relaxed text-muted-foreground">
          {t('credits.copyright')}
        </p>

        {/* The terms specify their own wording, so it is kept verbatim
            alongside the translation rather than replaced by it. */}
        <details className="text-xs">
          <summary className="cursor-pointer text-muted-foreground hover:text-foreground">
            {t('credits.originalWording')}
          </summary>

          <div className="mt-2 space-y-2 border-l-2 border-muted pl-3 text-muted-foreground">
            <p>
              Thanks to The Indie Stone for creating Project Zomboid, which made this
              possible.
            </p>
            <p>
              This is an unofficial fan production for non-commercial purposes made under the
              Indie Stone Terms.
            </p>
            <p>
              Project Zomboid and its assets are © The Indie Stone Ltd. This project is not
              affiliated with or endorsed by The Indie Stone.
            </p>
          </div>
        </details>

        <div className="flex flex-wrap gap-4 pt-1">
          <a
            href={PROJECT_ZOMBOID}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1.5 text-sm text-primary hover:underline"
          >
            projectzomboid.com
            <ExternalLink className="size-3" />
          </a>

          <a
            href={INDIE_STONE_TERMS}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1.5 text-sm text-primary hover:underline"
          >
            {t('credits.terms')}
            <ExternalLink className="size-3" />
          </a>
        </div>
      </section>

      <section className="max-w-2xl space-y-3 rounded-md border p-4">
        <SectionMark label={t('credits.whatIsUsed')} />

        <dl className="space-y-2 text-sm">
          <div>
            <dt className="font-medium">{t('credits.models')}</dt>
            <dd className="text-muted-foreground">{t('credits.modelsWhere')}</dd>
          </div>

          <div>
            <dt className="font-medium">{t('credits.tiles')}</dt>
            <dd className="text-muted-foreground">{t('credits.tilesWhere')}</dd>
          </div>

          <div>
            <dt className="font-medium">{t('credits.icons')}</dt>
            <dd className="text-muted-foreground">{t('credits.iconsWhere')}</dd>
          </div>
        </dl>

        {/* Mod assets belong to their authors, and the terms are explicit
            that their permission is a separate matter. */}
        <p className="text-xs text-muted-foreground">{t('credits.mods')}</p>
      </section>

      <section className="max-w-2xl space-y-3 rounded-md border p-4">
        <SectionMark label={t('credits.dataProtection')} />

        <p className="text-sm text-muted-foreground">{t('credits.dataProtectionBody')}</p>

        <Link to="/settings" className="inline-block text-sm text-primary hover:underline">
          {t('credits.dataProtectionLink')}
        </Link>
      </section>

      <section className="max-w-2xl space-y-3 rounded-md border p-4">
        <SectionMark label={t('credits.mapTiles')} />

        <p className="text-sm text-muted-foreground">{t('credits.mapTilesWhere')}</p>

        <a
          href="https://map.projectzomboid.com"
          target="_blank"
          rel="noreferrer"
          className="inline-flex items-center gap-1.5 text-sm text-primary hover:underline"
        >
          projectzomboidmap.com
          <ExternalLink className="size-3" />
        </a>
      </section>
    </div>
  )
}
