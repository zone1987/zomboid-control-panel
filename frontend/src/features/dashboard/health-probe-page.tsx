import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'

import { apiFetch } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'

type HealthResponse = {
  status: string
  database: string
  setupComplete: boolean
  time: string
}

export function HealthProbePage() {
  const { t } = useTranslation()

  const { data, error, isPending } = useQuery({
    queryKey: ['health'],
    queryFn: () => apiFetch<HealthResponse>('/health'),
  })

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <h1 className="text-2xl font-semibold">API health</h1>

      {isPending && <Skeleton className="h-32 w-full" />}

      {error && (
        <Alert variant="destructive">
          <AlertTitle>{t('errors.network')}</AlertTitle>
          <AlertDescription>{error.message}</AlertDescription>
        </Alert>
      )}

      {data && (
        <Card>
          <CardHeader>
            <CardTitle>{data.status}</CardTitle>
          </CardHeader>
          <CardContent>
            <dl className="grid grid-cols-[10rem_1fr] gap-y-2 text-sm">
              <dt className="text-muted-foreground">Database</dt>
              <dd>{data.database}</dd>
              <dt className="text-muted-foreground">Setup complete</dt>
              <dd>{data.setupComplete ? 'yes' : 'no'}</dd>
              <dt className="text-muted-foreground">Server time</dt>
              <dd>{data.time}</dd>
            </dl>
          </CardContent>
        </Card>
      )}

      <Button variant="outline" asChild>
        <Link to="/">{t('common.back')}</Link>
      </Button>
    </div>
  )
}
