import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { Sparkles } from 'lucide-react'

import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

/**
 * What the panel displays and where each picture came from.
 *
 * `generated` is its own field rather than a sentence in the origin: a
 * picture made in the style of the game is not the game's artwork, and
 * a reader skimming the list has to be able to see that at a glance.
 */
type Asset = {
  id: string
  generated?: boolean
}

const ASSETS: Asset[] = [
  { id: 'scene', generated: true },
  { id: 'mark', generated: false },
  { id: 'models' },
  { id: 'tiles' },
  { id: 'icons' },
  { id: 'mapTiles' },
]

export function AssetsCard() {
  const { t } = useTranslation()

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settings.assetsTitle')}</CardTitle>
        <CardDescription>{t('settings.assetsDescription')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        <ul className="space-y-3">
          {ASSETS.map((asset) => (
            <li key={asset.id} className="space-y-1 border-b pb-3 last:border-0 last:pb-0">
              <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{t(`settings.asset.${asset.id}`)}</span>

                {asset.generated === true && (
                  <Badge variant="outline" className="gap-1 text-xs font-normal">
                    <Sparkles className="size-3" />
                    {t('settings.assetGenerated')}
                  </Badge>
                )}
              </div>

              <p className="text-sm text-muted-foreground">
                {t(`settings.assetOrigin.${asset.id}`)}
              </p>
            </li>
          ))}
        </ul>

        {/* The credits page is what The Indie Stone's terms rest on, so
            this list points at it rather than restating it. */}
        <p className="text-xs text-muted-foreground">
          {t('settings.assetsCredits')}{' '}
          <Link to="/credits" className="text-primary hover:underline">
            {t('nav.credits')}
          </Link>
        </p>
      </CardContent>
    </Card>
  )
}
