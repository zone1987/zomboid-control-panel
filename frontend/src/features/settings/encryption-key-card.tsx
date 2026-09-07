import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Check, Copy, Eye, EyeOff, ShieldAlert, ShieldCheck } from 'lucide-react'

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { readGeneratedSecrets } from './settings'

/**
 * The encryption key, for a deployment that generated its own.
 *
 * Hidden behind a click rather than shown outright: the page is often
 * open on a shared screen, and the key is worth as much as every stored
 * server password together.
 */
export function EncryptionKeyCard() {
  const { t } = useTranslation()
  const [revealed, setRevealed] = useState(false)
  const [copied, setCopied] = useState(false)

  const { data, isPending } = useQuery({
    queryKey: ['settings', 'generated-secrets'],
    queryFn: readGeneratedSecrets,
    staleTime: Infinity,
  })

  async function copy(key: string) {
    await navigator.clipboard.writeText(key)
    setCopied(true)
    window.setTimeout(() => setCopied(false), 2000)
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settings.encryptionKeyTitle')}</CardTitle>
        <CardDescription>{t('settings.encryptionKeyDescription')}</CardDescription>
      </CardHeader>

      <CardContent className="space-y-4">
        {isPending && <Skeleton className="h-24 w-full" />}

        {/* A key the operator set is not shown: they already hold it, and
            returning it would only widen where it exists. */}
        {!isPending && data?.generated === false && (
          <Alert>
            <ShieldCheck className="size-4" />
            <AlertTitle>{t('settings.encryptionKeyOwnTitle')}</AlertTitle>
            <AlertDescription>{t('settings.encryptionKeyOwnBody')}</AlertDescription>
          </Alert>
        )}

        {!isPending && data?.generated === true && data.key !== null && (
          <>
            <Alert variant="destructive">
              <ShieldAlert className="size-4" />
              <AlertTitle>{t('settings.encryptionKeyWarnTitle')}</AlertTitle>
              <AlertDescription>{t('settings.encryptionKeyWarnBody')}</AlertDescription>
            </Alert>

            <div className="flex flex-wrap items-center gap-2">
              <div className="relative min-w-0 flex-1">
                <Input
                  readOnly
                  type={revealed ? 'text' : 'password'}
                  value={data.key}
                  aria-label={t('settings.encryptionKeyTitle')}
                  className="pr-10 font-mono"
                  onFocus={(event) => event.currentTarget.select()}
                />

                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="absolute inset-y-0 right-0 h-full w-10 text-muted-foreground hover:bg-transparent"
                  aria-label={
                    revealed ? t('settings.encryptionKeyHide') : t('settings.encryptionKeyShow')
                  }
                  aria-pressed={revealed}
                  onClick={() => setRevealed((shown) => !shown)}
                >
                  {revealed ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                </Button>
              </div>

              <Button
                type="button"
                variant="outline"
                onClick={() => void copy(data.key ?? '')}
              >
                {copied ? <Check className="size-4" /> : <Copy className="size-4" />}
                {copied ? t('settings.encryptionKeyCopied') : t('settings.encryptionKeyCopy')}
              </Button>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  )
}
