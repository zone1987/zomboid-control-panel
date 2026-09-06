import { describe, expect, it } from 'vitest'

import type { RenderProgress } from '@/features/settings/render-progress'
import {
  overallPercent,
  remainingSeconds,
  segmentOf,
  segments,
  throughput,
} from './render-estimate'

const NOW = 1_800_000_000_000
const seconds = (value: number) => NOW / 1000 - value

function running(extra: Partial<RenderProgress> = {}): RenderProgress {
  return { state: 'running', ...extra }
}

describe('which segment a phase belongs to', () => {
  it('keeps rendering and uploading in one segment', () => {
    // They alternate once per batch -- hundreds of times -- so as
    // separate steps the marker would swing back and forth for hours.
    expect(segmentOf('rendering')).toBe('drawing')
    expect(segmentOf('uploading')).toBe('drawing')
    expect(segmentOf('verifying')).toBe('drawing')
    expect(segmentOf('retrying')).toBe('drawing')
  })

  it('puts preparation and the survey before it, and the sweep after', () => {
    expect(segmentOf('queued')).toBe('survey')
    expect(segmentOf('clearing')).toBe('survey')
    expect(segmentOf('surveying')).toBe('survey')
    expect(segmentOf('sweeping')).toBe('finish')
    expect(segmentOf('finishing')).toBe('finish')
  })
})

describe('remaining time', () => {
  it('says nothing while the survey is running', () => {
    const progress = running({ phase: 'surveying', surveyStartedAt: seconds(600) })

    expect(remainingSeconds(progress, NOW)).toBeNull()
  })

  it('says nothing in the first seconds of drawing', () => {
    const progress = running({
      phase: 'rendering',
      drawingStartedAt: seconds(10),
      tilesUploaded: 20,
      tilesEstimated: 197_000,
    })

    expect(remainingSeconds(progress, NOW)).toBeNull()
  })

  it('measures from the drawing, not from the run', () => {
    // 1000 tiles in 100 s is 10/s; 4000 left is 400 s. Had it counted
    // the 3600 s survey as well the answer would be nearly ten times.
    const progress = running({
      phase: 'uploading',
      startedAt: seconds(3700),
      surveyStartedAt: seconds(3700),
      surveyFinishedAt: seconds(100),
      drawingStartedAt: seconds(100),
      tilesUploaded: 1000,
      tilesEstimated: 5000,
    })

    expect(remainingSeconds(progress, NOW)).toBe(400)
  })

  it('reports nothing left rather than a negative figure', () => {
    const progress = running({
      drawingStartedAt: seconds(100),
      tilesUploaded: 6000,
      tilesEstimated: 5000,
    })

    expect(remainingSeconds(progress, NOW)).toBe(0)
  })
})

describe('throughput', () => {
  it('reports tiles and bytes a second once there is enough to measure', () => {
    const progress = running({
      drawingStartedAt: seconds(100),
      tilesUploaded: 1500,
      bytesUploaded: 350_000_000,
    })

    const rate = throughput(progress, NOW)

    expect(rate?.tilesPerSecond).toBeCloseTo(15, 5)
    expect(rate?.bytesPerSecond).toBeCloseTo(3_500_000, 5)
  })

  it('says nothing before the drawing has begun', () => {
    expect(throughput(running({ phase: 'surveying' }), NOW)).toBeNull()
  })
})

describe('the timeline', () => {
  it('always sums to the whole bar', () => {
    for (const phase of ['surveying', 'rendering', 'uploading', 'retrying', 'sweeping']) {
      const parts = segments(
        running({ phase, surveyStartedAt: seconds(3600), drawingStartedAt: seconds(600) }),
        NOW,
      )

      expect(parts.reduce((sum, part) => sum + part.share, 0)).toBeCloseTo(1, 10)
    }
  })

  it('drops the survey segment when the answer came from the store', () => {
    // A second run reads the occupancy map instead of walking the world,
    // so a survey segment would be a step that never happens.
    const parts = segments(
      running({
        phase: 'rendering',
        surveyStartedAt: seconds(600),
        surveyFinishedAt: seconds(600),
        drawingStartedAt: seconds(600),
      }),
      NOW,
    )

    expect(parts.map((part) => part.key)).toEqual(['drawing', 'finish'])
    expect(parts.reduce((sum, part) => sum + part.share, 0)).toBeCloseTo(1, 10)
  })

  it('gives the drawing far more width than the survey', () => {
    const parts = segments(
      running({
        phase: 'rendering',
        surveyStartedAt: seconds(4200),
        surveyFinishedAt: seconds(600),
        drawingStartedAt: seconds(600),
        tilesUploaded: 30_000,
        tilesEstimated: 197_000,
      }),
      NOW,
    )

    const survey = parts.find((part) => part.key === 'survey')
    const drawing = parts.find((part) => part.key === 'drawing')

    expect(drawing?.share).toBeGreaterThan(survey?.share ?? 1)
  })

  it('marks what is done, what runs and what waits', () => {
    const parts = segments(
      running({
        phase: 'rendering',
        surveyStartedAt: seconds(4200),
        surveyFinishedAt: seconds(600),
        drawingStartedAt: seconds(600),
      }),
      NOW,
    )

    expect(parts.find((part) => part.key === 'survey')?.state).toBe('done')
    expect(parts.find((part) => part.key === 'drawing')?.state).toBe('active')
    expect(parts.find((part) => part.key === 'finish')?.state).toBe('waiting')
  })

  it('never runs backwards when uploading follows rendering', () => {
    const base = {
      surveyStartedAt: seconds(4200),
      surveyFinishedAt: seconds(600),
      drawingStartedAt: seconds(600),
      tilesEstimated: 197_000,
    }

    const rendering = overallPercent(running({ ...base, phase: 'rendering', tilesUploaded: 30_000 }), NOW)
    const uploading = overallPercent(running({ ...base, phase: 'uploading', tilesUploaded: 30_000 }), NOW)

    expect(uploading).toBe(rendering)
  })
})
