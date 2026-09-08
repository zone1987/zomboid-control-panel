import { apiFetch } from '@/lib/api'
import { chunkOffsets } from '@/lib/chunks'

export type VehicleModelStatus = {
  models: number
  textures: number
  bytes: number
  available: boolean
  names: string[]
}

export type ModelUploadOutcome = {
  name: string
  failed: boolean
  error?: string
}

export function vehicleModelStatus(): Promise<VehicleModelStatus> {
  return apiFetch('/vehicle-models')
}

/**
 * Uploaded in batches rather than all at once or one at a time.
 *
 * A game installation holds 146 models and 399 textures: a single
 * request is far past what PHP accepts, and one request per file is
 * hundreds of round trips.
 *
 * Kept under PHP's max_file_uploads, which is 20 here and silently
 * drops whatever a request carries beyond it.
 */
const FILES_PER_REQUEST = 15

/**
 * Above this a file goes on its own, in pieces.
 *
 * The base game's largest vehicle model is under a megabyte, so this
 * never fires today -- but a mod may ship something far larger, and
 * PHP refuses an oversized request at startup, before any code runs,
 * answering HTML rather than a reason worth reading.
 */
export const MODEL_CHUNK_BYTES = 8 * 1024 * 1024

/** Which files have to travel alone because one request cannot hold them. */
export function needsChunking(file: { size: number }): boolean {
  return file.size > MODEL_CHUNK_BYTES
}

/** Sends one oversized model in pieces the container will accept. */
async function uploadInPieces(file: File): Promise<ModelUploadOutcome> {
  try {
    for (const offset of chunkOffsets(file.size, MODEL_CHUNK_BYTES)) {
      const body = new FormData()
      body.append('name', file.name)
      body.append('offset', String(offset))
      body.append('chunk', file.slice(offset, offset + MODEL_CHUNK_BYTES))

      await apiFetch('/vehicle-models/chunk', { method: 'POST', body })
    }

    await apiFetch('/vehicle-models/finish', {
      method: 'POST',
      body: { name: file.name, bytes: file.size },
    })

    return { name: file.name, failed: false }
  } catch (error) {
    return {
      name: file.name,
      failed: true,
      error: error instanceof Error ? error.message : undefined,
    }
  }
}

export async function uploadVehicleModels(
  files: File[],
  onProgress?: (done: number, total: number) => void,
): Promise<ModelUploadOutcome[]> {
  const outcomes: ModelUploadOutcome[] = []
  let done = 0

  // The large ones first and alone; the rest travel in batches, which
  // is far fewer round trips for the 591 files a game install holds.
  const large = files.filter(needsChunking)
  const small = files.filter((file) => !needsChunking(file))

  for (const file of large) {
    outcomes.push(await uploadInPieces(file))
    onProgress?.(++done, files.length)
  }

  for (let start = 0; start < small.length; start += FILES_PER_REQUEST) {
    const batch = small.slice(start, start + FILES_PER_REQUEST)
    const body = new FormData()

    for (const file of batch) {
      body.append('files[]', file)
    }

    try {
      const answer = await apiFetch<{ results: ModelUploadOutcome[] }>(
        '/vehicle-models/upload',
        { method: 'POST', body },
      )

      outcomes.push(...answer.results)
    } catch (error) {
      // The whole batch failed, so every file in it did.
      for (const file of batch) {
        outcomes.push({
          name: file.name,
          failed: true,
          error: error instanceof Error ? error.message : undefined,
        })
      }
    }

    done += batch.length
    onProgress?.(Math.min(done, files.length), files.length)
  }

  return outcomes
}

export function deleteVehicleModel(name: string): Promise<void> {
  return apiFetch(`/vehicle-models/${encodeURIComponent(name)}`, { method: 'DELETE' })
}
