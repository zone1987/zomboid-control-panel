import { useMutation, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Play } from 'lucide-react'

import { errorField } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { useActiveServer } from '@/features/servers/active-server'
import { listServers } from '@/features/servers/servers'
import { startWorldRender } from '@/features/settings/render-progress'

/**
 * Starts a render, from the header rather than from one page.
 *
 * The map is where somebody notices it needs redrawing, but so is the
 * player list or a report of a missing building; sending them to the
 * settings for a button is a detour past what they were looking at.
 */
export function StartRenderButton() {
  const { t } = useTranslation()
  const { activeServerId } = useActiveServer()

  const { data: servers } = useQuery({ queryKey: ['servers'], queryFn: listServers })
  const target = activeServerId ?? servers?.items[0]?.id ?? null

  const start = useMutation({
    mutationFn: () => startWorldRender(target ?? ''),
    onSuccess: () => toast.success(t('settings.render.started')),
    onError: (error) => {
      const key = errorField(error, 'error')

      toast.error(
        key === null
          ? t('errors.generic')
          : t(`settings.render.${key.split('.').pop()}`, t('errors.generic')),
      )
    },
  })

  return (
    <Button
      type="button"
      size="sm"
      variant="secondary"
      className="ml-auto"
      disabled={target === null || start.isPending}
      onClick={() => start.mutate()}
    >
      <Play className="size-4" />
      {t('map.render.startHere')}
    </Button>
  )
}
