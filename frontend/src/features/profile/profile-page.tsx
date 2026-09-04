import { useTranslation } from 'react-i18next'

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { useAuth } from '@/features/auth/auth-context'
import { PasskeyManager } from './passkey-manager'

export function ProfilePage() {
  const { t } = useTranslation()
  const { user } = useAuth()

  if (!user) {
    return null
  }

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <h1 className="text-2xl font-semibold">{t('profile.title')}</h1>

      <Card>
        <CardHeader>
          <CardTitle>{user.displayName}</CardTitle>
          <CardDescription>{user.email}</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-wrap gap-2">
          {user.roles.map((role) => (
            <Badge key={role} variant="secondary">
              {role.replace('ROLE_', '').toLowerCase()}
            </Badge>
          ))}
        </CardContent>
      </Card>

      <PasskeyManager />
    </div>
  )
}
