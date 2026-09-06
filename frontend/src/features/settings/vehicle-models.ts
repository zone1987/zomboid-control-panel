import { apiFetch } from '@/lib/api'

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

export async function uploadVehicleModels(
  files: File[],
  onProgress?: (done: number, total: number) => void,
): Promise<ModelUploadOutcome[]> {
  const outcomes: ModelUploadOutcome[] = []

  for (let start = 0; start < files.length; start += FILES_PER_REQUEST) {
    const batch = files.slice(start, start + FILES_PER_REQUEST)
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

    onProgress?.(Math.min(start + batch.length, files.length), files.length)
  }

  return outcomes
}

export function deleteVehicleModel(name: string): Promise<void> {
  return apiFetch(`/vehicle-models/${encodeURIComponent(name)}`, { method: 'DELETE' })
}
