import type { RenderProgress } from '@/features/settings/render-progress'

/**
 * What a run has left, and how fast it is going.
 *
 * Every function here answers null rather than guessing. A run measured
 * in hours is watched by somebody deciding whether to wait, and a
 * figure invented from too little evidence is worse than no figure --
 * it is believed.
 */

/** A phase belongs to exactly one segment of the timeline. */
export type SegmentKey = 'survey' | 'drawing' | 'finish'

export type Segment = {
  key: SegmentKey
  /** Fraction of the whole bar, summing to 1 across the segments. */
  share: number
  state: 'done' | 'active' | 'waiting'
  /** How far through this segment, 0..1. Only meaningful when active. */
  progress: number
}

const SURVEY_PHASES = new Set(['queued', 'starting', 'clearing', 'surveying'])
const FINISH_PHASES = new Set(['finishing', 'sweeping'])

/**
 * Which segment a phase belongs to.
 *
 * rendering, uploading, verifying and retrying all live in the drawing
 * segment: they alternate once per batch of six cells, hundreds of
 * times, and as separate steps the marker would swing back and forth
 * for hours.
 */
export function segmentOf(phase: string | undefined): SegmentKey {
  if (phase === undefined || SURVEY_PHASES.has(phase)) {
    return 'survey'
  }

  return FINISH_PHASES.has(phase) ? 'finish' : 'drawing'
}

/**
 * Seconds of drawing left, or null while nothing honest can be said.
 *
 * Measured from drawingStartedAt rather than startedAt: the survey is
 * fifty minutes in which no tile is produced, and counting them would
 * roughly halve the apparent rate.
 */
export function remainingSeconds(progress: RenderProgress, now = Date.now()): number | null {
  const started = progress.drawingStartedAt
  const estimated = progress.tilesEstimated ?? 0
  const done = progress.tilesUploaded ?? 0

  if (started === null || started === undefined || estimated <= 0 || done <= 0) {
    return null
  }

  const elapsed = now / 1000 - started

  // A rate over the first seconds is noise, not a measurement.
  if (elapsed < 30) {
    return null
  }

  const perSecond = done / elapsed

  if (perSecond <= 0) {
    return null
  }

  return Math.max(0, Math.round((estimated - done) / perSecond))
}

export type Throughput = { tilesPerSecond: number; bytesPerSecond: number }

/** The rate so far, which is what shows a store starting to refuse. */
export function throughput(progress: RenderProgress, now = Date.now()): Throughput | null {
  const started = progress.drawingStartedAt

  if (started === null || started === undefined) {
    return null
  }

  const elapsed = now / 1000 - started

  if (elapsed < 30) {
    return null
  }

  return {
    tilesPerSecond: (progress.tilesUploaded ?? 0) / elapsed,
    bytesPerSecond: (progress.bytesUploaded ?? 0) / elapsed,
  }
}

/**
 * How wide each segment is drawn, and how far each has come.
 *
 * The shares are the segments' measured time cost, not equal thirds: a
 * survey is under an hour and the drawing four, so equal steps would
 * tell the operator something untrue about where the time goes.
 *
 * On a second run the survey is answered from the stored occupancy map
 * in no time at all, and its segment disappears rather than sitting
 * there at zero width pretending to be a step.
 */
export function segments(progress: RenderProgress, now = Date.now()): Segment[] {
  const current = segmentOf(progress.phase)
  const surveySeconds = surveyDuration(progress, now)

  // Skipped entirely when the survey came out of the store.
  const showSurvey = surveySeconds === null || surveySeconds > 5

  const drawingSeconds = estimatedDrawingSeconds(progress, now)
  const weights: Record<SegmentKey, number> = {
    survey: showSurvey ? (surveySeconds ?? 600) : 0,
    drawing: drawingSeconds,
    // Sweeping the store is minutes against the drawing's hours.
    finish: 120,
  }

  const total = weights.survey + weights.drawing + weights.finish
  const order: SegmentKey[] = showSurvey ? ['survey', 'drawing', 'finish'] : ['drawing', 'finish']
  const rank: Record<SegmentKey, number> = { survey: 0, drawing: 1, finish: 2 }

  return order.map((key) => ({
    key,
    share: weights[key] / total,
    state: rank[key] < rank[current] ? 'done' : rank[key] === rank[current] ? 'active' : 'waiting',
    progress: key === current ? progressWithin(key, progress) : rank[key] < rank[current] ? 1 : 0,
  }))
}

/** How long the survey took, or has taken so far. */
function surveyDuration(progress: RenderProgress, now: number): number | null {
  const started = progress.surveyStartedAt

  if (started === null || started === undefined) {
    return null
  }

  const finished = progress.surveyFinishedAt ?? now / 1000

  return Math.max(0, finished - started)
}

/** Four hours until the run itself says otherwise. */
function estimatedDrawingSeconds(progress: RenderProgress, now: number): number {
  const remaining = remainingSeconds(progress, now)
  const started = progress.drawingStartedAt

  if (remaining !== null && started !== null && started !== undefined) {
    return Math.max(1, now / 1000 - started + remaining)
  }

  return 4 * 3600
}

function progressWithin(key: SegmentKey, progress: RenderProgress): number {
  if (key === 'survey') {
    const done = progress.cellsSurveyed ?? 0
    // 78 by 64 is the grid the survey walks; the total it will report
    // is what the survey is working out, so it cannot be used here.
    return clamp(done / (78 * 64))
  }

  if (key === 'drawing') {
    const estimated = progress.tilesEstimated ?? 0

    return estimated > 0 ? clamp((progress.tilesUploaded ?? 0) / estimated) : 0
  }

  return 0
}

function clamp(value: number): number {
  return Number.isFinite(value) ? Math.min(1, Math.max(0, value)) : 0
}

/** Whole percent across the run, for the collapsed bar. */
export function overallPercent(progress: RenderProgress, now = Date.now()): number {
  return Math.round(
    segments(progress, now).reduce((sum, segment) => sum + segment.share * segment.progress, 0) *
      100,
  )
}
