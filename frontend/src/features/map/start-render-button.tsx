import { useEffect, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Loader2, Play } from 'lucide-react'

import { errorField } from '@/lib/api'
import { Button } from '@/components/ui/button'
import { useActiveServer } from '@/features/servers/active-server'
import { listServers } from '@/features/servers/servers'
import {
  pauseWorldRender,
  renderProgress,
  startWorldRender,
  type RenderProgress,
} from '@/features/settings/render-progress'

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
  const [progress, setProgress] = useState<RenderProgress>({ state: 'idle' })

  const { data: servers } = useQuery({ queryKey: ['servers'], queryFn: listServers })
  const target = activeServerId ?? servers?.items[0]?.id ?? null

  useEffect(() => {
    let alive = true

    const ask = () => {
      void renderProgress()
        .then((state) => alive && setProgress(state))
        .catch(() => undefined)
    }

    ask()

    const timer = window.setInterval(ask, 3_000)

    return () => {
      alive = false
      window.clearInterval(timer)
    }
  }, [])

  const paused = progress.state === 'running' && progress.paused === true
  const running = progress.state === 'running' && !paused

  const act = useMutation({
    // A paused run continues where it stopped; a cancelled one has no
    // worker left to resume, so it starts again -- and skips every cell
    // whose data has not changed, which is most of them.
    mutationFn: () => (paused ? pauseWorldRender(true) : startWorldRender(target ?? '')),
    onSuccess: () => toast.success(paused ? t('map.render.resumed') : t('settings.render.started')),
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
      className="ml-auto"
      disabled={running || target === null || act.isPending}
      onClick={() => act.mutate()}
    >
      {running ? <Loader2 className="size-4 animate-spin" /> : <Play className="size-4" />}
      {running
        ? t('map.render.inProgress')
        : paused
          ? t('map.render.resume')
          : t('map.render.startHere')}
    </Button>
  )
}
