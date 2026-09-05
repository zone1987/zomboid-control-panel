import { apiFetch } from '@/lib/api'

export type TexturePack = {
  name: string
  present: boolean
  bytes: number
  /** How much of an interrupted upload already arrived. */
  received: number
}

export type TextureStatus = {
  required: TexturePack[]
  complete: boolean
  totalBytes: number
  chunkBytes: number
}

export function textureStatus(): Promise<TextureStatus> {
  return apiFetch<TextureStatus>('/map/textures')
}

export function deleteTexturePack(name: string): Promise<TextureStatus> {
  return apiFetch<TextureStatus>(`/map/textures/${encodeURIComponent(name)}`, { method: 'DELETE' })
}

/**
 * Sends one pack in pieces.
 *
 * Tiles2x.pack is 306 MB against a container that accepts 16 MB, so a
 * single request was never an option. The piece size comes from the
 * server rather than being agreed twice.
 */
export async function uploadTexturePack(
  file: File,
  chunkBytes: number,
  onProgress: (sent: number) => void,
  signal?: AbortSignal,
): Promise<void> {
  for (let offset = 0; offset < file.size; offset += chunkBytes) {
    if (signal?.aborted === true) {
      throw new DOMException('Aborted', 'AbortError')
    }

    const body = new FormData()
    body.append('name', file.name)
    body.append('offset', String(offset))
    body.append('chunk', file.slice(offset, offset + chunkBytes))

    await apiFetch<{ received: number }>('/map/textures/chunk', { method: 'POST', body, signal })

    onProgress(Math.min(offset + chunkBytes, file.size))
  }

  await apiFetch<{ complete: boolean }>('/map/textures/finish', {
    method: 'POST',
    body: { name: file.name, bytes: file.size },
    signal,
  })
}
