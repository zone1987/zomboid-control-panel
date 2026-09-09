import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { ExternalLink } from 'lucide-react'

import { cn } from '@/lib/utils'
import { SectionMark } from '@/components/layout/section-mark'

const PROJECT_ZOMBOID = 'https://projectzomboid.com'
const INDIE_STONE_TERMS = 'https://theindiestone.com/forums/index.php?/tos/'

/**
 * The attribution itself, shared by the page and the dialog.
 *
 * One definition on purpose: this is the notice The Indie Stone's terms
 * require, and two copies of it would eventually say different things.
 * The page is what the terms rest on — linkable, and findable by
 * somebody who was never shown the dialog — so the dialog is a
 * convenience over it, never a replacement.
 */
export function CreditsContent({
  className,
  onNavigate,
}: {
  className?: string
  /** Given by the dialog so an internal link can close it first. */
  onNavigate?: () => void
}) {
  const { t } = useTranslation()

  return (
    <div className={cn('space-y-4 [&_section]:max-w-2xl', className)}>
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

      {/* Named apart from the extracted assets above on purpose: the
          backdrop was generated, not taken from the game, and listing it
          beside them would claim a provenance it does not have. */}
      <section className="max-w-2xl space-y-3 rounded-md border p-4">
        <SectionMark label={t('credits.artwork')} />

        <p className="text-sm text-muted-foreground">{t('credits.artworkBody')}</p>
      </section>

      <section className="max-w-2xl space-y-3 rounded-md border p-4">
        <SectionMark label={t('credits.dataProtection')} />

        <p className="text-sm text-muted-foreground">{t('credits.dataProtectionBody')}</p>

        <Link
          to="/settings"
          onClick={onNavigate}
          className="inline-block text-sm text-primary hover:underline"
        >
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
