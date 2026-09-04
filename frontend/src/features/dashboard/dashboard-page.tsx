import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

export function DashboardPage() {
  const { t } = useTranslation()

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold">{t('nav.dashboard')}</h1>
        <p className="text-muted-foreground">{t('servers.empty')}</p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Backend connectivity</CardTitle>
          <CardDescription>
            Confirms the SPA reaches the Symfony API through the dev proxy.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Button asChild>
            <Link to="/health">Check API</Link>
          </Button>
        </CardContent>
      </Card>
    </div>
  )
}
