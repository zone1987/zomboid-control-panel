import { useTranslation } from 'react-i18next'

import type { RenderProgress } from '@/features/settings/render-progress'
import { segments, type Segment } from './render-estimate'

/**
 * The run as one bar, its parts as wide as the time they take.
 *
 * Not a stepper: preparing takes seconds, the survey under an hour and
 * the drawing four, so equal steps would say something untrue about
 * where the time goes. Width carries the meaning here -- the survey
 * reads as a prelude without anybody having to read a word.
 */
export function RenderTimeline({ progress }: { progress: RenderProgress }) {
  const { t } = useTranslation()
  const parts = segments(progress)

  return (
    <div className="space-y-1.5">
      <div className="flex h-2 w-full gap-0.5 overflow-hidden rounded-full">
        {parts.map((part) => (
          <SegmentBar key={part.key} segment={part} />
        ))}
      </div>

      <div className="flex w-full gap-0.5 text-[0.65rem] leading-tight text-muted-foreground">
        {parts.map((part) => (
          <div
            key={part.key}
            className="min-w-0 truncate"
            style={{ width: `${part.share * 100}%` }}
          >
            <span className={part.state === 'active' ? 'text-foreground' : undefined}>
              {t(`map.render.segment.${part.key}`)}
            </span>
          </div>
        ))}
      </div>
    </div>
  )
}

function SegmentBar({ segment }: { segment: Segment }) {
  const filled = segment.state === 'done' ? 1 : segment.state === 'active' ? segment.progress : 0

  return (
    <div
      className="h-full overflow-hidden rounded-full bg-muted"
      style={{ width: `${segment.share * 100}%` }}
    >
      <div
        className={`h-full transition-[width] duration-500 ${
          segment.state === 'done' ? 'bg-primary/50' : 'bg-primary'
        }`}
        style={{ width: `${filled * 100}%` }}
      />
    </div>
  )
}
