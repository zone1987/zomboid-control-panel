import { apiFetch } from '@/lib/api'

export type RenderState = 'idle' | 'running' | 'done' | 'failed' | 'stopped'

export type RenderProgress = {
  state: RenderState
  phase?: string
  startedAt?: number
  finishedAt?: number
  cellsTotal?: number
  cellsDone?: number
  cellsRendered?: number
  cellsEmpty?: number
  tilesUploaded?: number
  tilesFailed?: number
  bytesHeld?: number
  objectsHeld?: number
  currentFloor?: number
  floorsTotal?: number
  bytesUploaded?: number
  currentCell?: string
  currentTile?: string
  tilesRemoved?: number
  stopRequested?: boolean
  paused?: boolean
  batchTotal?: number
  batchDone?: number
  batchPending?: number
  tilesSkipped?: number
  tilesEstimated?: number
  tilesProduced?: number
  cellsSkipped?: number
  error?: string
}

export function startWorldRender(serverId: string, fresh = false): Promise<{ status: string }> {
  return apiFetch<{ status: string }>(`/map/render/${serverId}`, { method: 'POST', body: { fresh } })
}

/**
 * Watches a render as it happens.
 *
 * EventSource rather than polling: a three-hour job checked once a
 * second is ten thousand requests, and the interesting part is the
 * tile names going past, which only a stream shows honestly.
 */
export function stopWorldRender(): Promise<{ status: string }> {
  return apiFetch<{ status: string }>('/map/render/stop', { method: 'POST', body: {} })
}

export function pauseWorldRender(resume = false): Promise<{ status: string }> {
  return apiFetch<{ status: string }>('/map/render/pause', { method: 'POST', body: { resume } })
}

export function renderProgress(): Promise<RenderProgress> {
  return apiFetch<RenderProgress>('/map/render')
}

export function watchRender(
  onProgress: (progress: RenderProgress) => void,
  onClosed: () => void,
): () => void {
  // Same-origin cookies ride along automatically; EventSource cannot
  // set an Authorization header, which is why the session is a cookie.
  const source = new EventSource('/api/map/render/stream', { withCredentials: true })

  source.onmessage = (event) => {
    try {
      onProgress(JSON.parse(event.data) as RenderProgress)
    } catch {
      // A truncated frame is not worth tearing the stream down for.
    }
  }

  source.onerror = () => {
    // The server closes every 55 seconds by design and the browser
    // reconnects; only a closed connection means it is really over.
    if (source.readyState === EventSource.CLOSED) {
      onClosed()
    }
  }

  return () => source.close()
}
